<?php

namespace App\Services\Dashboard;

use App\Services\Analysis\HotelNameMatcher;
use Illuminate\Support\HtmlString;

/**
 * Safe HTML for an AI answer: the text is escaped first, **bold** markdown is
 * kept, the hotel's name gets a strong highlight and competitors a light one.
 * Bold names are matched fuzzily (HotelNameMatcher); plain text matches the
 * exact names.
 */
final class AnswerHighlighter
{
    public function __construct(private readonly HotelNameMatcher $matcher) {}

    /**
     * @param  list<string>  $competitors
     */
    public function highlight(string $answer, string $hotelName, string $city, array $competitors = []): HtmlString
    {
        $parts = preg_split('/\*\*(.+?)\*\*/su', $answer, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$answer];
        $html = '';

        foreach ($parts as $i => $part) {
            $isBold = $i % 2 === 1;

            if ($isBold) {
                $html .= $this->boldName($part, $hotelName, $city, $competitors);
            } else {
                $html .= $this->markExactNames(e($part), $hotelName, $competitors);
            }
        }

        return new HtmlString($html);
    }

    /**
     * @param  list<string>  $competitors
     */
    private function boldName(string $text, string $hotelName, string $city, array $competitors): string
    {
        if ($this->matcher->matches($text, $hotelName, [$city])) {
            return '<mark class="cv-mark-own">'.e($text).'</mark>';
        }

        foreach ($competitors as $competitor) {
            if ($this->matcher->matches($text, $competitor, [$city])) {
                return '<mark class="cv-mark-competitor">'.e($text).'</mark>';
            }
        }

        return '<strong>'.e($text).'</strong>';
    }

    /**
     * @param  list<string>  $competitors
     */
    private function markExactNames(string $escaped, string $hotelName, array $competitors): string
    {
        $names = [$hotelName => 'cv-mark-own'] + array_fill_keys($competitors, 'cv-mark-competitor');

        foreach ($names as $name => $class) {
            if (trim($name) === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])('.preg_quote(e($name), '/').')(?![\p{L}\p{N}])/iu';
            $escaped = preg_replace($pattern, '<mark class="'.$class.'">$1</mark>', $escaped) ?? $escaped;
        }

        return $escaped;
    }
}
