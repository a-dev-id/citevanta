<?php

namespace App\Services;

use App\Models\AiResponse;
use App\Models\Hotel;
use App\Models\HotelAssessment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VisibilityAnalysis
{
    public function __construct(private CitationAnalysis $citations) {}

    public function isValid(AiResponse $response): bool
    {
        $raw = $response->raw_metadata;

        return $response->run?->status === 'completed' && $response->status === 'completed' && trim($response->text ?? '') !== '' && ! $response->error_details && ($raw['status'] ?? null) === 'completed' && collect($raw['output'] ?? [])->contains(fn ($item) => is_array($item) && ($item['type'] ?? '') === 'web_search_call' && ($item['status'] ?? '') === 'completed');
    }

    public function analyze(AiResponse $response): void
    {
        if (! $this->isValid($response)) {
            return;
        }
        $hotels = Hotel::orderBy('id')->get();
        $response->load('citations');
        $fingerprint = hash('sha256', json_encode(['v2.4', $response->text, $hotels->toArray(), $response->citations->map(fn ($c) => [$c->id, $c->url, $c->title, $c->start_index, $c->end_index, $c->manual, $c->manual ? $c->classification : null, $c->manual ? $c->hotel_id : null])], JSON_UNESCAPED_UNICODE));
        if ($response->analysis_fingerprint === $fingerprint) {
            return;
        }
        DB::transaction(function () use ($response, $hotels, $fingerprint) {
            AiResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();
            $response->load('citations');
            foreach ($response->citations as $citation) {
                $this->citations->classify($citation, $hotels);
            }
            $detected = $this->detect($response->text, $hotels);
            $ids = [];
            foreach ($detected['hotels'] as $hotelId => $data) {
                $ids[] = $hotelId;
                $assessment = $response->assessments()->firstOrNew(['hotel_id' => $hotelId]);
                if ($assessment->manual) {
                    continue;
                }
                $support = $this->supportingCitationIds($data, $response->citations, mb_strlen($response->text));
                $assessment->fill([...$data, 'supporting_citation_ids' => array_values(array_unique($support)), 'rule' => 'verified-alias+recommendation-context+comparative-order:v2.4']);
                $assessment->save();
            }
            $response->assessments()->where('manual', false)->whereNotIn('hotel_id', $ids)->delete();
            $response->update(['unresolved_hotels' => $detected['unresolved'], 'analysis_fingerprint' => $fingerprint, 'analyzed_at' => now()]);
        });
    }

    /** Recalculate display findings without rewriting historical answers, sources or saved assessments. */
    public function preview(AiResponse $response): void
    {
        if (! $this->isValid($response)) {
            return;
        }
        $hotels = Hotel::orderBy('id')->get();
        $response->loadMissing(['citations', 'assessments']);
        $citations = $response->citations->map(function ($source) use ($hotels) {
            $copy = clone $source;
            $this->citations->classify($copy, $hotels, false);
            $copy->setRelation('hotel', $hotels->firstWhere('id', $copy->hotel_id));

            return $copy;
        });
        $detected = $this->detect($response->text, $hotels);
        $assessments = collect();
        foreach ($detected['hotels'] as $id => $finding) {
            $saved = $response->assessments->firstWhere('hotel_id', $id);
            $assessment = $saved ? clone $saved : new HotelAssessment(['hotel_id' => $id, 'ai_response_id' => $response->id]);
            if (! $assessment->manual) {
                $assessment->fill([...$finding, 'supporting_citation_ids' => $this->supportingCitationIds($finding, $citations, mb_strlen($response->text))]);
            } elseif ($assessment->rank !== $finding['rank']) {
                $assessment->rank = null;
            }
            $assessment->setRelation('hotel', $hotels->firstWhere('id', $id));
            $assessments->push($assessment);
        }
        foreach ($response->assessments->where('manual', true) as $saved) {
            if (! $assessments->contains('hotel_id', $saved->hotel_id)) {
                $copy = clone $saved;
                $copy->rank = null;
                $copy->setRelation('hotel', $hotels->firstWhere('id', $copy->hotel_id));
                $assessments->push($copy);
            }
        }
        $response->setRelation('citations', $citations);
        $response->setRelation('assessments', $assessments);
        $response->unresolved_hotels = $detected['unresolved'];
    }

    private function supportingCitationIds(array $data, Collection $citations, int $textLength): array
    {
        return $citations->filter(function ($citation) use ($data, $textLength) {
            if ($citation->start_index === null || $citation->end_index === null || $citation->start_index < 0 || $citation->end_index <= $citation->start_index || $citation->end_index > $textLength) {
                return false;
            }
            foreach ($data['occurrences'] as $occurrence) {
                if ($citation->start_index !== null && $citation->end_index !== null && $citation->start_index < ($occurrence['context_end'] ?? $occurrence['end']) && $citation->end_index > ($occurrence['context_start'] ?? $occurrence['start'])) {
                    return true;
                }
            }

            return false;
        })->pluck('id')->unique()->values()->all();
    }

    /** URL targets are masked to avoid counting a name repeated inside a citation URL. Character positions remain aligned. */
    public function detect(string $text, Collection $hotels): array
    {
        $masked = preg_replace_callback('~https?://[^\s)]+~u', fn ($m) => str_repeat(' ', mb_strlen($m[0])), $text);
        $hits = [];
        $unresolved = [];
        foreach ($hotels as $hotel) {
            $aliases = array_values(array_unique([$hotel->name, ...$hotel->aliases]));
            usort($aliases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $pattern = '/(?<![\pL\pN])(?:'.implode('|', array_map(fn ($a) => preg_quote($a, '/'), $aliases)).')(?![\pL\pN])/iu';
            preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$name,$byte]) {
                $start = mb_strlen(substr($masked, 0, $byte));
                $lineBefore = mb_substr($text, 0, $start);
                $lineStart = mb_strrpos($lineBefore, "\n");
                $lineContext = explode("\n", mb_substr($text, $lineStart === false ? 0 : $lineStart + 1))[0];
                if (! str_contains($name, ' ') && preg_match('/\b(temple|pavilion|village|not (?:a|the) (?:business|brand|company|hotel|resort))\b/iu', $lineContext)) {
                    $unresolved[] = ['name' => $name, 'reason' => 'Short name appears in an ambiguous context; verify the business identity manually.'];

                    continue;
                }
                $hits[] = ['hotel' => $hotel, 'name' => $name, 'start' => $start, 'end' => $start + mb_strlen($name)];
            }
        }
        $ambiguous = [];
        foreach ($hits as $i => $hit) {
            foreach ($hits as $j => $other) {
                if ($i !== $j && $hit['hotel']->id !== $other['hotel']->id && $hit['start'] < $other['end'] && $hit['end'] > $other['start']) {
                    $ambiguous[$i] = true;
                    $unresolved[] = ['name' => $hit['name'], 'reason' => 'Ambiguous identity: aliases overlap across verified brands.'];
                }
            }
        }
        $hits = array_values(array_filter($hits, fn ($hit, $i) => ! isset($ambiguous[$i]), ARRAY_FILTER_USE_BOTH));
        $blocks = preg_split('/\n[ \t]*\n/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        $context = false;
        $negativeContext = false;
        $found = [];
        $rankEvidence = [];
        $unordered = $this->hasUnorderedDisclaimer($text);
        foreach ($blocks as [$block,$byte]) {
            $start = mb_strlen(substr($text, 0, $byte));
            $end = $start + mb_strlen($block);
            $blockHits = array_values(array_filter($hits, fn ($h) => $h['start'] >= $start && $h['start'] < $end));
            $plain = preg_replace('~\[([^\]]+)\]\(https?://[^)]+\)~u', '$1', $block);
            $plain = str_replace(['**', '__'], '', $plain);
            $negative = $this->negative($plain);
            if ($blockHits) {
                $prefix = mb_substr($block, 0, min(array_column($blockHits, 'start')) - $start);
                if (preg_match('/\b(recommend(?:ed|ations)?|top|best|consider)\b/iu', $prefix)) {
                    $context = ! $this->negative($prefix);
                    $negativeContext = $this->negative($prefix);
                }
            }
            if (! $blockHits && preg_match('/(?:recommend|top|best|consider|mention|context|rank|alternatives)/iu', $plain)) {
                $context = (bool) preg_match('/\b(recommend(?:ed|ations)?|top|best|consider|alternatives)\b/iu', $plain) && ! $negative;
                $negativeContext = $negative || (bool) preg_match('/(?:mentioned only|context only|not recommendations)/iu', $plain);
            }
            foreach ($blockHits as $hit) {
                $hotel = $hit['hotel'];
                $localOffset = $hit['start'] - $start;
                $before = mb_substr($block, 0, $localOffset);
                $lineStart = mb_strrpos($before, "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $line = explode("\n", mb_substr($block, $lineStart))[0];
                $lineEnd = $start + $lineStart + mb_strlen($line);
                $singleBlock = count(array_unique(array_map(fn ($h) => $h['hotel']->id, $blockHits))) === 1;
                $lineHits = array_filter($blockHits, fn ($h) => $h['start'] >= $start + $lineStart && $h['start'] < $lineEnd);
                $singleLine = count(array_unique(array_map(fn ($h) => $h['hotel']->id, $lineHits))) === 1;
                $line = preg_replace('~\[([^\]]+)\]\(https?://[^)]+\)~u', '$1', $line);
                $line = str_replace(['**', '__'], '', $line);
                $heading = (bool) preg_match('/^\s*(?:(?:#{1,6}|[-*])\s*)?(?:(?:#\s*)?\d{1,3}(?:[.)]|st|nd|rd|th)\s*)?'.preg_quote($hit['name'], '/').'/iu', $line);
                $decisionText = $this->decisionContext($text, $hit['start'], $hit['end']);
                $explicit = (bool) preg_match('/\b(recommend(?:ed)?|consider|top choice)\b/iu', $decisionText);
                $citationOnly = (bool) preg_match('/\b(cited|referenced|linked|(?:source|citation|reference) only)\b/iu', $decisionText);
                $recommended = ! $this->negative($decisionText) && ($explicit || (! $negativeContext && $heading && $context && ! $citationOnly));
                $rank = null;
                if ($recommended && ! $unordered && $heading && preg_match('/^\s*(?:#{1,6}\s*)?(?:#\s*)?(\d{1,3})(?:[.)]|st|nd|rd|th)\s+/iu', $line, $order)) {
                    $rank = (int) $order[1];
                    if ($rank < 1) {
                        $rank = null;
                    }
                }
                if (! isset($found[$hotel->id])) {
                    $found[$hotel->id] = ['mentioned' => true, 'recommended' => false, 'rank' => null, 'evidence' => $block, 'occurrences' => []];
                }
                $item = &$found[$hotel->id];
                $item['occurrences'][] = ['start' => $hit['start'], 'end' => $hit['end'], 'context_start' => $singleBlock ? $start : ($singleLine ? $start + $lineStart : $hit['start']), 'context_end' => $singleBlock ? $end : ($singleLine ? $lineEnd : $hit['end'])];
                if ($recommended) {
                    $item['recommended'] = true;
                    $item['evidence'] = $block;
                    if ($rank !== null) {
                        $rankEvidence[$hotel->id][] = $rank;
                        $item['rank'] = $item['rank'] !== null && $item['rank'] !== $rank ? null : $rank;
                    }
                }
                unset($item);
            }
            if (! $blockHits && $context && preg_match('/^\s*(?:#{1,6}\s+|\*\*|\d+[.)]\s+)\[?([^\]\n]+?)\]?(?:\(https?:\/\/[^)]+\))?(?:\*\*)?\s*$/u', trim($block), $candidate) && ! preg_match('/\b(recommendations?|rankings?|order|sources?|citations?)\b/iu', $candidate[1])) {
                $unresolved[] = ['name' => mb_substr(trim($candidate[1], '*# '), 0, 200), 'reason' => 'Unverified or ambiguous name; not included as a verified business identity.'];
            }
        }
        $numberedBrands = collect($found)->filter(fn ($entry) => $entry['recommended'] && $entry['rank'] !== null);
        $comparative = (bool) preg_match('/\b(?:ranked\s*(?::|(?:from|in|by|recommendations|businesses|brands|options|choices|shortlist|list|comparison)\b)|rankings?\s*:|order of preference\b|best to worst\b|highest to lowest\b|ordered by (?:preference|quality|rating)\b)/iu', $text);
        if (! $comparative || $numberedBrands->count() < 2 || $numberedBrands->pluck('rank')->unique()->count() < 2) {
            foreach ($found as &$entry) {
                $entry['rank'] = null;
            } unset($entry);
        }
        foreach ($rankEvidence as $id => $positions) {
            if (count(array_unique($positions)) > 1) {
                $found[$id]['rank'] = null;
            }
        }
        $ranks = array_values(array_filter(array_column($found, 'rank'), fn ($r) => $r !== null));
        if (count(array_unique($ranks)) !== count($ranks)) {
            foreach ($found as &$entry) {
                $entry['rank'] = null;
            }unset($entry);
        }

        return ['hotels' => $found, 'unresolved' => array_values(array_unique($unresolved, SORT_REGULAR))];
    }

    private function decisionContext(string $text, int $start, int $end): string
    {
        $before = mb_substr($text, 0, $start);
        $after = mb_substr($text, $end);
        preg_match('/(?:^|[.!?;]\s+|\n|\s+(?:but|while|whereas)\s+)([^.!?;\n]*)$/iu', $before, $prefix);
        $tail = preg_split('/[.!?;](?:\s|$)|\n|\s+(?:but|while|whereas)\s+/iu', $after, 2)[0];

        return ($prefix[1] ?? '').mb_substr($text, $start, $end - $start).$tail;
    }

    public function hasUnorderedDisclaimer(string $text): bool
    {
        return (bool) preg_match('/\b(in no particular order|not ranked|not (?:a |an )?(?:comparative )?rankings?|(?:no|without) (?:meaningful |comparative |implied )?rankings?|unordered|alphabetical(?: order)?|arbitrary order|no order of preference|number(?:ing|ed) (?:only |just )?for (?:reference|convenience))\b/iu', $text);
    }

    private function negative(string $text): bool
    {
        return (bool) preg_match('/\b(not recommended|(?:do not|cannot|can\x{2019}t|can\x27t|would not|not) recommend|avoid|not a recommendation|not suitable|not included|excluded|mentioned only|context only|for context|not endorsed|not an endorsement|source only|citation only|reference only|closed|unavailable)\b/iu', $text);
    }
}
