<?php

namespace App\Services\Ai;

use App\Models\Hotel;
use App\Services\SimulatedMonitoring;
use App\Services\TenantContext;

class SimulatedProvider implements AiProviderAdapter
{
    public const MODEL = 'simulated-monitoring-v1';

    public function execute(array $settings): array
    {
        app(SimulatedMonitoring::class)->assertAvailable();
        $brand = app(TenantContext::class)->brand();
        $competitor = Hotel::where('identifier', '!=', $brand->slug)->orderBy('id')->first();
        $scenario = (int) ($settings['scenario'] ?? 0) % 4;
        $text = match ($scenario) {
            0 => '1. '.$brand->name.' is recommended for this query.',
            1 => $brand->name.' is mentioned for context only, not recommended.',
            2 => ($competitor?->name ?? 'Another verified business').' is recommended for this query.',
            default => $brand->name.' is recommended. Compare it with '.($competitor?->name ?? 'other verified businesses').'.',
        };
        $text = "SIMULATED TEST RESULT — not a genuine provider observation.\n\n".$text;
        $url = $scenario % 2 === 0 ? $brand->official_website : 'https://simulation.example.test/review';

        return ['simulated' => true, 'status' => 'completed', 'model' => self::MODEL, 'text' => $text, 'citations' => [['url' => $url, 'domain' => parse_url($url, PHP_URL_HOST), 'title' => 'Simulated source', 'is_official' => $scenario % 2 === 0, 'start_index' => 0, 'end_index' => mb_strlen($text)]], 'output' => [['type' => 'web_search_call', 'status' => 'completed']]];
    }
}
