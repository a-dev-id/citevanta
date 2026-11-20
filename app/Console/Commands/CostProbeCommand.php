<?php

namespace App\Console\Commands;

use App\Enums\AiCallKind;
use App\Models\Hotel;
use App\Services\Analysis\OpenAiAnswerExtractor;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\AiSpend;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetGuard;
use App\Services\Engines\EngineResult;
use App\Services\Engines\OpenAiEngine;
use App\Services\Engines\OpenAiLimits;
use App\Services\Scanning\CostCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenAI\Contracts\ClientContract;
use Throwable;

/**
 * Real cost of answers before and after the cost limits (max_tool_calls,
 * search_context_size, max_output_tokens, reasoning effort): asks the same
 * prompts of one hotel with both settings, analyses each answer, and prints
 * cost, tokens, web searches and whether hotels are still named. Calls go
 * through BudgetGuard (admin bucket) and are recorded; nothing is saved as a scan.
 */
#[Signature('citevanta:cost-probe {hotel : Hotel id} {--count=10 : Prompts per setting} {--only= : before or after} {--yes : Do not ask}')]
#[Description('Measure the real cost per answer before and after the cost limits (real OpenAI calls)')]
class CostProbeCommand extends Command
{
    public function handle(ClientContract $client, BudgetGuard $guard, AiSpend $spend, CostCalculator $costs): int
    {
        $hotel = Hotel::query()->find($this->argument('hotel'));
        if ($hotel === null) {
            $this->error('Hotel not found.');

            return self::INVALID;
        }

        $count = max(1, min(20, (int) $this->option('count')));
        $prompts = $hotel->prompts()->where('is_active', true)->where('language', 'en')->orderBy('id')->limit($count)->get();
        $modes = $this->option('only') ? [(string) $this->option('only')] : ['before', 'after'];
        $estimate = $spend->estimateCalls($prompts->count() * count($modes));

        $this->info("{$hotel->name}: {$prompts->count()} prompts x ".count($modes).' settings, estimated $'.number_format($estimate, 2).' (admin budget).');
        if (! $this->option('yes') && ! $this->confirm('Make these real OpenAI calls?')) {
            return self::FAILURE;
        }

        $scanModel = (string) config('citevanta.scan_model');
        $analysisModel = (string) config('citevanta.analysis_model');
        $tool = (string) config('citevanta.web_search.tool');
        $context = AiCallContext::admin();
        $rows = [];

        foreach ($modes as $mode) {
            $engine = new OpenAiEngine($client, $scanModel, $tool, (string) config('citevanta.web_search.tool_choice'),
                $mode === 'before' ? ['tools' => [['type' => $tool]]] : OpenAiLimits::scan($tool));
            $extractor = new OpenAiAnswerExtractor($client, $analysisModel, $mode === 'before' ? [] : OpenAiLimits::analysis());
            $stats = ['answers' => 0, 'errors' => 0, 'answer_cost' => 0.0, 'analysis_cost' => 0.0, 'in' => 0, 'out' => 0, 'searches' => 0, 'naming' => 0, 'hotels' => 0, 'chars' => 0, 'examples' => []];

            foreach ($prompts as $prompt) {
                try {
                    /** @var EngineResult $answer */
                    $answer = $guard->run($context, AiCallKind::Answer, fn () => $engine->ask($prompt->text),
                        fn (EngineResult $r) => [$scanModel, $r->tokensIn, $r->tokensOut, $r->webSearchCalls]);
                    $analysis = $guard->run($context, AiCallKind::Analysis,
                        fn () => $extractor->extract($answer->text, $hotel->name, (string) $hotel->city, []),
                        fn ($a) => [$analysisModel, $a->tokensIn, $a->tokensOut]);
                } catch (BudgetExceeded $e) {
                    $this->error('Stopped by the budget guard: '.$e->getMessage());

                    break 2;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->warn("  {$mode} #{$prompt->id}: ".class_basename($e).' '.mb_substr($e->getMessage(), 0, 150));

                    continue;
                }

                $named = count($analysis->hotels);
                $stats['answers']++;
                $stats['answer_cost'] += $costs->tokens($scanModel, $answer->tokensIn, $answer->tokensOut) + $costs->webSearch($answer->webSearchCalls);
                $stats['analysis_cost'] += $costs->tokens($analysisModel, $analysis->tokensIn, $analysis->tokensOut);
                $stats['in'] += $answer->tokensIn;
                $stats['out'] += $answer->tokensOut;
                $stats['searches'] += $answer->webSearchCalls;
                $stats['naming'] += (int) ($named > 0);
                $stats['hotels'] += $named;
                $stats['chars'] += mb_strlen($answer->text);
                if (count($stats['examples']) < 3) {
                    $stats['examples'][] = mb_substr($prompt->text, 0, 60).' -> '.implode(', ', array_slice(array_map(fn ($h) => is_array($h) ? (string) ($h['name'] ?? '') : (string) $h, $analysis->hotels), 0, 4));
                }
                $this->line(sprintf('  %-6s #%d: %d in / %d out tokens, %d searches, %d hotels named', $mode, $prompt->id, $answer->tokensIn, $answer->tokensOut, $answer->webSearchCalls, $named));
            }

            $rows[$mode] = $stats;
        }

        $this->newLine();
        $this->table(
            ['Setting', 'Answers', 'Cost / answer (answer + analysis)', 'Answer only', 'Analysis', 'Tokens in', 'Tokens out', 'Searches / answer', 'Answers naming hotels', 'Hotels named / answer', 'Answer length'],
            collect($rows)->map(function (array $s, string $mode) {
                $n = max(1, $s['answers']);

                return [
                    $mode, $s['answers'].($s['errors'] ? " ({$s['errors']} errors)" : ''),
                    '$'.number_format(($s['answer_cost'] + $s['analysis_cost']) / $n, 5),
                    '$'.number_format($s['answer_cost'] / $n, 5),
                    '$'.number_format($s['analysis_cost'] / $n, 5),
                    (int) round($s['in'] / $n), (int) round($s['out'] / $n),
                    number_format($s['searches'] / $n, 2),
                    $s['naming'].' of '.$s['answers'],
                    number_format($s['hotels'] / $n, 1),
                    (int) round($s['chars'] / $n).' chars',
                ];
            })->values()->all(),
        );

        foreach ($rows as $mode => $s) {
            foreach ($s['examples'] as $example) {
                $this->line("  {$mode}: {$example}");
            }
        }

        return self::SUCCESS;
    }
}
