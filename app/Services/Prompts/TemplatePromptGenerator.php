<?php

namespace App\Services\Prompts;

use App\Enums\Persona;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Traveller prompts from config/prompts.php: two per persona and language,
 * written naturally per language, round-robin across personas.
 *
 * Placeholders: {city}, {country}, {type} (the hotel type in that language),
 * {types} (plural, English), {market} (market templates).
 */
final class TemplatePromptGenerator implements PromptGenerator
{
    public const FALLBACK_LANGUAGE = 'en';

    /** Languages whose prompts start with a capital letter after filling in {type}. */
    private const CASED = ['en', 'id'];

    private const MAX_MARKET_PROMPTS = 2;

    /** @return list<string> */
    public static function supportedLanguages(): array
    {
        return array_keys((array) config('prompts.templates', []));
    }

    public function generate(PromptContext $context, int $count = 10): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Prompt count must be at least 1.');
        }

        $templates = (array) config('prompts.templates', []);
        $language = array_key_exists($context->language, $templates) ? $context->language : self::FALLBACK_LANGUAGE;
        $replacements = $this->replacements($context, $language);
        $prompts = [];

        foreach ($this->interleaved($templates[$language]) as [$persona, $template]) {
            if (count($prompts) >= $count) {
                break;
            }

            $text = $this->fill($template, $replacements, $language);

            // Guard: never leak the hotel's own name (e.g. if the name equals the city).
            if ($this->containsName($text, $context->hotelName)) {
                continue;
            }

            $prompts[] = new GeneratedPrompt($text, Persona::from($persona), $language);
        }

        $marketTemplates = (array) config("prompts.market_templates.{$language}", []);
        $markets = array_slice(array_values(array_filter(array_map('trim', $context->guestMarkets))), 0, self::MAX_MARKET_PROMPTS);

        foreach ($markets as $i => $market) {
            if ($marketTemplates === []) {
                break;
            }

            [$persona, $template] = $marketTemplates[$i % count($marketTemplates)];
            $text = $this->fill($template, $replacements + ['{market}' => $market], $language);

            if (! $this->containsName($text, $context->hotelName)) {
                $prompts[] = new GeneratedPrompt($text, Persona::from($persona), $language);
            }
        }

        return $prompts;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(PromptContext $context, string $language): array
    {
        $type = trim((string) $context->hotelType) !== '' ? Str::lower(trim((string) $context->hotelType)) : 'hotel';

        if ($language !== self::FALLBACK_LANGUAGE) {
            $words = (array) config("prompts.type_words.{$language}", []);
            $type = $words[$type] ?? $words['hotel'] ?? $type;
        }

        return [
            '{city}' => $context->city,
            '{country}' => $context->country,
            '{types}' => $language === self::FALLBACK_LANGUAGE ? self::pluralize($type) : $type,
            '{type}' => $type,
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function fill(string $template, array $replacements, string $language): string
    {
        $text = strtr($template, $replacements);

        return in_array($language, self::CASED, true) ? Str::ucfirst($text) : $text;
    }

    /**
     * Order templates round-robin so any $count spreads evenly across personas.
     *
     * @param  array<string, list<string>>  $byPersona
     * @return list<array{0: string, 1: string}>
     */
    private function interleaved(array $byPersona): array
    {
        $result = [];
        $max = max(array_map('count', $byPersona));

        for ($i = 0; $i < $max; $i++) {
            foreach ($byPersona as $persona => $templates) {
                if (isset($templates[$i])) {
                    $result[] = [$persona, $templates[$i]];
                }
            }
        }

        return $result;
    }

    private function containsName(string $text, string $hotelName): bool
    {
        $hotelName = trim($hotelName);

        return $hotelName !== ''
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($hotelName, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private static function pluralize(string $type): string
    {
        return str_ends_with($type, 's') ? $type : $type.'s';
    }
}
