<?php

namespace App\Services\Engines;

use App\Enums\AiBucket;

/**
 * Request settings that cap the cost of each OpenAI call (config
 * citevanta.ai_limits and citevanta.web_search).
 */
final class OpenAiLimits
{
    /** Free audits and trials: the cheapest settings. */
    public const FREE = 'free';

    /** Paying and Private plan scans: more searches and output, so answers name enough hotels. */
    public const PAID = 'paid';

    /**
     * Scan answers: web search with little search context, few searches,
     * capped output, low reasoning effort. $tier: FREE or PAID.
     *
     * @return array<string, mixed>
     */
    public static function scan(string $webSearchTool = 'web_search', string $tier = self::PAID): array
    {
        $scan = (array) config('citevanta.ai_limits.scan', []);
        $limits = (array) ($scan[$tier === self::FREE ? self::FREE : self::PAID] ?? []);

        return array_filter([
            'tools' => [array_filter([
                'type' => $webSearchTool,
                'search_context_size' => config('citevanta.web_search.search_context_size') ?: null,
            ])],
            'max_tool_calls' => ((int) ($limits['max_tool_calls'] ?? 0)) ?: null,
            'max_output_tokens' => ((int) ($limits['max_output_tokens'] ?? 0)) ?: null,
            'reasoning' => self::reasoning($scan['reasoning_effort'] ?? null),
        ], fn ($value) => $value !== null);
    }

    /**
     * Free audits and trials use the free tier; paid, Private plan and
     * admin scans use the paid tier.
     */
    public static function tierFor(AiBucket $bucket): string
    {
        return in_array($bucket, [AiBucket::Audits, AiBucket::Trials], true) ? self::FREE : self::PAID;
    }

    /**
     * Answer analysis: no tools, capped output.
     *
     * @return array<string, mixed>
     */
    public static function analysis(): array
    {
        $limits = (array) config('citevanta.ai_limits.analysis', []);

        return array_filter([
            'max_output_tokens' => ((int) ($limits['max_output_tokens'] ?? 0)) ?: null,
            'reasoning' => self::reasoning($limits['reasoning_effort'] ?? null),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{effort: string}|null
     */
    private static function reasoning(mixed $effort): ?array
    {
        return is_string($effort) && $effort !== '' ? ['effort' => $effort] : null;
    }
}
