<?php

namespace App\Services\Recommendations;

/**
 * Rewrites rule-built recommendations so they read naturally and specifically
 * for one hotel, in one AI call per scan. Must not add facts: the server keeps
 * key, category, effort, impact and evidence, and FactGuard flags any number
 * that is not in the facts or the rule text.
 */
interface RecommendationWriter
{
    /**
     * @param  array<string, string>  $facts
     * @param  list<array{key: string, title: string, why: string, steps: list<string>, where: string}>  $items
     */
    public function write(string $hotelName, string $city, array $facts, array $items): RecommendationWriterResult;
}
