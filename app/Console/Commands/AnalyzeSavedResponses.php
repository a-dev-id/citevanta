<?php

namespace App\Console\Commands;

use App\Services\VisibilityMetrics;
use Illuminate\Console\Command;

class AnalyzeSavedResponses extends Command
{
    protected $signature = 'intelligence:analyze';

    protected $description = 'Analyze saved completed responses locally; never calls an AI provider';

    public function handle(VisibilityMetrics $metrics): int
    {
        $runs = $metrics->runs();
        $this->line(json_encode(['summary' => $metrics->summary($runs), 'hotels' => $metrics->hotels($runs)->map(fn ($row) => ['hotel' => $row['hotel']->name, 'mentions' => $row['mentions'], 'recommendations' => $row['recommendations'], 'average_position' => $row['average_position'], 'citations' => $row['citations'], 'official_citations' => $row['official_citations']]), 'tests' => $runs->pluck('id')], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
