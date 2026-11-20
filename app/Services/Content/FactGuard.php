<?php

namespace App\Services\Content;

/**
 * Last line of defence against invented facts in AI-written text: any number
 * (a price, a distance, a time, a count) that does not appear in the
 * allowed source text (the hotel's facts and the evidence) is wrapped as
 * "[check: 15 minutes]" so the team sees it before copying.
 *
 * List numbering ("1. ", "2) ", "PART 2: ") and numbers inside placeholders are left alone.
 */
final class FactGuard
{
    /** A number with an optional unit right after it. */
    private const NUMBER = '/(?<![\w\[])(\d[\d.,:]*\d|\d)(\s?(?:%|km|kilometres?|kilometers?|miles?|m\b|minutes?|mins?|hours?|hrs?|nights?|rooms?|villas?|suites?|stars?|usd|idr|eur|aud|sgd|am|pm|years?))?/iu';

    /**
     * @param  list<string>  $allowedSources  text the numbers may come from
     */
    public static function check(string $text, array $allowedSources): string
    {
        $allowed = self::numbersIn(implode("\n", $allowedSources));

        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $i => $line) {
            // Keep list numbering at the start of a line.
            $prefix = '';
            if (preg_match('/^(\s*(?:\d{1,2}[.)]|Q\d{1,2}[.:)]?|PART \d{1,2}:)\s+)/u', $line, $m)) {
                $prefix = $m[1];
                $line = substr($line, strlen($prefix));
            }

            $parts = preg_split('/(\[[^\]]*\])/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];

            foreach ($parts as $p => $part) {
                if (str_starts_with($part, '[')) {
                    continue; // placeholder, already flagged
                }

                $parts[$p] = (string) preg_replace_callback(self::NUMBER, function (array $match) use ($allowed): string {
                    return in_array(self::digits($match[1]), $allowed, true) ? $match[0] : '[check: '.trim($match[0]).']';
                }, $part);
            }

            $lines[$i] = $prefix.implode('', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> normalised digit strings
     */
    private static function numbersIn(string $text): array
    {
        preg_match_all('/\d[\d.,:]*\d|\d/u', $text, $matches);

        return array_values(array_unique(array_map(fn ($n) => self::digits($n), $matches[0])));
    }

    private static function digits(string $number): string
    {
        return (string) preg_replace('/\D/', '', $number);
    }
}
