<?php

namespace App\Services\Recommendations;

/**
 * Offline writer (CITEVANTA_ENGINE=fake): keeps the rule text as written,
 * which is already specific to the hotel, and reports plausible token counts.
 */
final class FakeRecommendationWriter implements RecommendationWriter
{
    public const MODEL = 'fake-engine';

    public function write(string $hotelName, string $city, array $facts, array $items): RecommendationWriterResult
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item['key']] = ['title' => $item['title'], 'why' => $item['why'], 'steps' => $item['steps'], 'where' => $item['where']];
        }

        $length = strlen((string) json_encode($items));

        return new RecommendationWriterResult($result, intdiv($length, 4) + 40 * count($facts) + 500, intdiv($length, 4) + 50, self::MODEL);
    }
}
