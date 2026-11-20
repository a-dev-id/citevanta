<?php

namespace App\Console\Commands;

use App\Services\Analysis\AnswerExtractor;
use App\Services\Analysis\OpenAiAnswerExtractor;
use App\Services\Budget\AiSpend;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineManager;
use App\Services\Engines\OpenAiEngine;
use App\Services\Reporting\VisibilityReport;
use App\Services\Reporting\VisibilityReportBuilder;
use App\Services\Scanning\PreparedScan;
use App\Services\Scanning\ScanRequest;
use App\Services\Scanning\ScanService;
use App\Services\Scanning\ScanSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

class ScanTestCommand extends Command
{
    protected $signature = 'scan:test
        {hotel : Hotel name, e.g. "Alaya Resort Ubud"}
        {city : City the hotel is in}
        {--country= : Country (required)}
        {--competitors= : Comma-separated competitor hotel names (added if missing; existing ones are kept)}
        {--replace-competitors : Remove (soft delete) the hotel\'s competitors that are not in --competitors}
        {--force : Do not ask for confirmation when replacing competitors}
        {--runs= : Times each prompt is asked (default: CITEVANTA_RUNS_PER_PROMPT)}
        {--lang=en : Prompt language}
        {--type= : Property type used in prompts, e.g. resort, villa, boutique hotel}
        {--website= : Hotel website (stored on the hotel)}
        {--yes : Do not ask before making real AI calls}';

    protected $description = 'Scan one hotel against ChatGPT and print an AI visibility report';

    public function handle(): int
    {
        $country = trim((string) $this->option('country'));
        if ($country === '') {
            $this->error('The --country option is required, e.g. --country="Indonesia".');

            return self::INVALID;
        }

        $runs = $this->option('runs') !== null ? (int) $this->option('runs') : (int) config('citevanta.runs_per_prompt', 3);
        if ($runs < 1 || $runs > 20) {
            $this->error('--runs must be between 1 and 20.');

            return self::INVALID;
        }

        if (! $this->apiKeyAvailable()) {
            $this->error('OPENAI_API_KEY is not set. Add it to .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        $replace = (bool) $this->option('replace-competitors');

        if ($replace && $this->competitorList() === []) {
            $this->error('--replace-competitors needs a --competitors list.');

            return self::INVALID;
        }

        if ($replace && ! $this->option('force') && ! $this->confirm(
            'This removes every competitor of "'.$this->argument('hotel').'" that is not in --competitors. Continue?',
        )) {
            $this->warn('Cancelled. No competitors were changed.');

            return self::FAILURE;
        }

        $scans = $this->laravel->make(ScanService::class);
        $reports = $this->laravel->make(VisibilityReportBuilder::class);

        $prepared = $scans->prepare(new ScanRequest(
            hotelName: (string) $this->argument('hotel'),
            city: (string) $this->argument('city'),
            country: $country,
            competitors: $this->competitorList(),
            runs: $runs,
            language: strtolower(trim((string) $this->option('lang'))) ?: 'en',
            hotelType: $this->option('type') ?: null,
            website: $this->option('website') ?: null,
            replaceCompetitors: $replace,
        ));

        $this->intro($prepared);

        // Real AI calls count against AI_BUDGET_ADMIN: show the estimate and ask first.
        $estimate = $this->laravel->make(AiSpend::class)->estimateCalls($prepared->totalCalls());
        $this->line(sprintf('Estimated cost: $%.2f (admin AI budget).', $estimate));

        if (! $this->laravel->make(EngineManager::class)->isFake() && ! $this->option('yes')
            && ! $this->confirm(sprintf('Make %d real AI calls for about $%.2f?', $prepared->totalCalls(), $estimate))) {
            $prepared->scanRun->delete();
            $this->warn('Cancelled. No AI calls were made.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($prepared->totalCalls());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->start();

        $summary = $scans->runSync($prepared->scanRun, fn (int $done) => $bar->setProgress($done));

        $bar->finish();
        $this->newLine(2);

        $this->renderReport($prepared, $summary, $reports->build($prepared->scanRun->fresh(), $prepared->competitors));

        return $summary->answered > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function competitorList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option('competitors')))));
    }

    /** False when a real OpenAI service is bound but no API key is configured. */
    private function apiKeyAvailable(): bool
    {
        try {
            $engine = $this->laravel->make(EngineContract::class);
            $extractor = $this->laravel->make(AnswerExtractor::class);
        } catch (ApiKeyIsMissing) {
            return false;
        }

        $usesOpenAi = $engine instanceof OpenAiEngine || $extractor instanceof OpenAiAnswerExtractor;

        return ! $usesOpenAi || filled(config('openai.api_key'));
    }

    private function intro(PreparedScan $scan): void
    {
        $run = $scan->scanRun;

        $this->info("Scanning {$scan->hotel->name} ({$scan->hotel->city}, {$scan->hotel->country})");
        $this->line(sprintf(
            '  Scan run #%d · engine %s · model %s · analysis model %s',
            $run->id, $run->engine, $run->model, config('citevanta.analysis_model'),
        ));
        $this->line(sprintf(
            '  %d prompts × %d runs = %d answers to collect · competitors: %s',
            $scan->prompts->count(), $scan->runs, $scan->totalCalls(),
            $scan->competitors === [] ? 'none' : implode(', ', $scan->competitors),
        ));

        if ($scan->promptLanguage !== $scan->requestedLanguage) {
            $this->warn("  No prompt templates for '{$scan->requestedLanguage}' yet; using '{$scan->promptLanguage}'.");
        }

        $this->newLine();
    }

    private function renderReport(PreparedScan $scan, ScanSummary $summary, VisibilityReport $report): void
    {
        $this->info('AI VISIBILITY REPORT: '.$report->hotelName);
        $this->line(sprintf(
            '  Status: %s · answers collected %d/%d · analysed %d',
            $scan->scanRun->fresh()->status->value, $summary->answered, $summary->attempted, $summary->analyzed,
        ));
        $this->newLine();

        $this->line(sprintf(
            '<options=bold>Overall visibility: %s%%</> (%d of %d analysed answers mention the hotel)',
            $this->num($report->visibility), $report->mentions, $report->totalAnswers,
        ));
        $this->line('<options=bold>Average position when mentioned:</> '.(
            $report->averagePosition === null ? 'n/a (never ranked in a list)' : '#'.$this->num($report->averagePosition)
        ));
        $this->newLine();

        $this->line('<options=bold>Visibility by persona</>');
        $this->table(
            ['Persona', 'Answers', 'Mentions', 'Visibility'],
            collect($report->perPersona)->map(fn ($row, $persona) => [
                Str::ucfirst($persona), $row['answers'], $row['mentions'], $this->num($row['visibility']).'%',
            ])->values()->all(),
        );

        $this->line('<options=bold>Sentiment</>');
        $this->table(
            ['Sentiment', 'Answers'],
            collect($report->sentimentCounts)->map(fn ($count, $s) => [Str::of($s)->replace('_', ' ')->ucfirst(), $count])->values()->all(),
        );

        $this->line('<options=bold>Share of voice</>');
        $this->table(
            ['Hotel', 'Mentions', 'Visibility', 'Share of voice'],
            array_map(fn ($row) => [
                $row['is_target'] ? $row['name'].' (you)' : $row['name'],
                $row['mentions'],
                $this->num($row['visibility']).'%',
                $this->num($row['share_of_voice']).'%',
            ], $report->shareOfVoice),
        );

        $this->line('<options=bold>Top cited domains</>');
        if ($report->topDomains === []) {
            $this->line('  No citations returned.');
            $this->newLine();
        } else {
            $this->table(
                ['#', 'Domain', 'Citations'],
                array_map(fn ($row, $i) => [$i + 1, $row['domain'], $row['citations']], $report->topDomains, array_keys($report->topDomains)),
            );
        }

        $this->line(sprintf(
            '<options=bold>Total cost: $%s</> (%s tokens in, %s tokens out, %d web searches; includes analysis calls)',
            number_format($report->costUsd, 4), number_format($report->tokensIn), number_format($report->tokensOut), $report->webSearchCalls,
        ));

        foreach ($summary->unpricedModels as $model) {
            $this->warn("No price configured for model '{$model}'; its tokens were costed at \$0. Add it to config/citevanta.php (prices.models).");
        }

        if ($summary->errors !== []) {
            $this->newLine();
            $this->warn(sprintf('%d call(s) failed after retries and were skipped:', count($summary->errors)));
            foreach (array_slice($summary->errors, 0, 5) as $error) {
                $this->line('  - '.$error);
            }
            if (count($summary->errors) > 5) {
                $this->line('  ... see storage/logs/laravel.log for the rest.');
            }
        }
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.') ?: '0';
    }
}
