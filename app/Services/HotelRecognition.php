<?php

namespace App\Services;

use App\Models\Hotel;

class HotelRecognition
{
    /** Exact aliases are curated in config/intelligence.php; recommendation detection is deliberately conservative. */
    public function detect(string $text): array
    {
        $found = [];
        foreach (Hotel::all()->mapWithKeys(fn ($identity) => [$identity->identifier => array_unique([$identity->name, ...$identity->aliases])]) as $identifier => $aliases) {
            $pattern = '/(?<![\pL\pN])(?:'.implode('|', array_map(fn (string $alias) => preg_quote($alias, '/'), $aliases)).')(?![\pL\pN])/iu';
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$name, $offset]) {
                $before = substr($text, 0, $offset);
                $lineStart = strrpos($before, "\n");
                $line = substr($text, $lineStart === false ? 0 : $lineStart + 1);
                $line = strtok($line, "\n");
                $negative = preg_match('/\b(not recommended|do not recommend|avoid|not a recommendation|not suitable|closed|unavailable|not included|excluded)\b/i', $line);
                $positive = preg_match('/^\s*(?:#{1,6}\s+|\d+[.)]\s+|[-*]\s+)(?:\*\*)?'.preg_quote($name, '/').'/iu', $line) || preg_match('/\b(?:recommend|recommended|consider|top choice)\b/i', $line);
                $found[] = ['hotel_name' => $name, 'normalized_identifier' => $identifier, 'position' => mb_strlen($before), 'is_nandini' => $identifier === app(TenantContext::class)->brand()->slug, 'is_recommendation' => (bool) ($positive && ! $negative), 'matching_rule' => 'exact-alias+same-line-positive-with-negative-exclusion:v1'];
            }
        }
        usort($found, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $found;
    }
}
