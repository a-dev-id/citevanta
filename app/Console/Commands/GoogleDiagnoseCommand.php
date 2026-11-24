<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Google\GoogleDiagnostics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-diagnose {hotel : Hotel id or part of its name} {--date= : Day for the single-day live test (default: 10 days ago)} {--source=chatgpt.com : GA4 session source for the single-day test} {--days=28 : Length of the period compared}')]
#[Description('Compare what we stored from GA4 for a hotel with what GA4 returns now (read-only)')]
class GoogleDiagnoseCommand extends Command
{
    public function handle(GoogleDiagnostics $diagnostics): int
    {
        $hotel = ctype_digit((string) $this->argument('hotel'))
            ? Hotel::query()->find((int) $this->argument('hotel'))
            : Hotel::query()->where('name', 'like', '%'.$this->argument('hotel').'%')->first();

        if ($hotel === null || $hotel->googleLink?->ga4_property === null) {
            $this->error('No hotel with a linked GA4 property found.');

            return self::FAILURE;
        }

        foreach ($diagnostics->run($hotel, $this->option('date') ?: null, (string) $this->option('source'), (int) $this->option('days')) as $line) {
            $this->line($line, null, 'normal');
        }

        return self::SUCCESS;
    }
}
