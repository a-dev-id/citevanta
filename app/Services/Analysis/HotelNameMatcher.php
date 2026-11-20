<?php

namespace App\Services\Analysis;

use Illuminate\Support\Str;

/**
 * Fuzzy hotel-name matching that tolerates the ways AI answers rewrite names:
 * case, accents, punctuation, generic words ("Hotel", "Resort", "& Spa", "The")
 * and the city name ("Alaya Resort Ubud" == "Alaya Ubud" == "ALAYA").
 */
final class HotelNameMatcher
{
    /** Generic words that do not identify a property. */
    private const STOPWORDS = [
        'hotel', 'hotels', 'resort', 'resorts', 'spa', 'the', 'and', 'at', 'by', 'a', 'an',
    ];

    /**
     * Descriptive words many unrelated properties share. A "fuller name" match
     * needs at least one word that is not in this list.
     */
    private const WEAK_TOKENS = [
        'village', 'garden', 'gardens', 'beach', 'bay', 'palace', 'grand', 'royal', 'house',
        'villa', 'villas', 'inn', 'lodge', 'suites', 'suite', 'boutique', 'park', 'city',
        'central', 'plaza', 'view', 'hill', 'hills', 'river', 'jungle', 'retreat', 'residence',
        'residences', 'luxury', 'collection', 'international', 'new', 'old', 'town', 'sea',
    ];

    /** Generic words in Chinese, Korean and Japanese hotel names ("hotel", "resort", "villa"...). */
    private const CJK_GENERIC = [
        '精品酒店', '度假酒店', '度假村', '大酒店', '酒店', '饭店', '宾馆', '别墅', '民宿', '旅馆',
        '리조트', '호텔', '풀빌라', '빌라', '게스트하우스',
        'リゾート', 'ホテル', 'ヴィラ', '旅館',
    ];

    /** Minimum similar_text() percentage to treat two names as typos of each other. */
    private const SIMILARITY_THRESHOLD = 90.0;

    /**
     * @param  list<string>  $ignore  extra words to drop (e.g. the city name)
     * @return list<string>
     */
    public function tokens(string $name, array $ignore = []): array
    {
        $all = $this->rawTokens($name);
        $drop = array_merge(self::STOPWORDS, $this->rawTokens(implode(' ', $ignore)));

        $kept = array_values(array_diff($all, $drop));

        if ($kept !== []) {
            return $kept;
        }

        // Name made only of ignored words (e.g. "The Ubud Hotel"): keep the city words.
        return array_values(array_diff($all, self::STOPWORDS));
    }

    /** @param  list<string>  $ignore */
    public function normalize(string $name, array $ignore = []): string
    {
        return implode(' ', $this->tokens($name, $ignore));
    }

    /**
     * Whether text contains Chinese, Japanese or Korean characters. Those
     * scripts have no spaces between words, so names are compared as a
     * whole string instead of word by word.
     */
    public static function isCjk(string $text): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    }

    /** @param  list<string>  $ignore */
    public function matches(string $a, string $b, array $ignore = []): bool
    {
        if (self::isCjk($a) || self::isCjk($b)) {
            return $this->cjkMatches($a, $b);
        }

        $ta = $this->tokens($a, $ignore);
        $tb = $this->tokens($b, $ignore);

        if ($ta === [] || $tb === []) {
            return false;
        }

        $na = implode(' ', $ta);
        $nb = implode(' ', $tb);

        if ($na === $nb) {
            return true;
        }

        // One name is a fuller version of the other: "Mandapa" vs "Mandapa, a Ritz-Carlton Reserve".
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        $isSubset = array_diff($short, $long) === [];
        $distinctive = array_filter(array_diff($short, self::WEAK_TOKENS), fn ($t) => mb_strlen($t) >= 4);

        if ($isSubset && $distinctive !== []) {
            return true;
        }

        similar_text($na, $nb, $percent);

        return $percent >= self::SIMILARITY_THRESHOLD;
    }

    /**
     * Index of the first name in $names that matches $target, or null.
     *
     * @param  list<string>  $names
     * @param  list<string>  $ignore
     */
    public function indexIn(array $names, string $target, array $ignore = []): ?int
    {
        foreach (array_values($names) as $i => $name) {
            if ($this->matches($name, $target, $ignore)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether $name appears in free text as a contiguous run of tokens.
     *
     * @param  list<string>  $ignore
     */
    public function appearsInText(string $text, string $name, array $ignore = []): bool
    {
        if (self::isCjk($name)) {
            $needle = $this->compact($name);

            return mb_strlen($needle) >= 2 && str_contains($this->compact($text), $needle);
        }

        $needle = $this->tokens($name, $ignore);

        if ($needle === [] || (count($needle) === 1 && mb_strlen($needle[0]) < 4)) {
            return false;
        }

        $haystack = ' '.implode(' ', $this->rawTokens($text)).' ';

        return str_contains($haystack, ' '.implode(' ', $needle).' ');
    }

    /**
     * Whether free text (e.g. a prompt) names the hotel. Catches the full name
     * ("Alaya Resort Ubud") and its distinctive core without the city and
     * generic words ("Alaya"), but not the city alone or a generic word alone
     * ("village" for "Ubud Village Resort").
     */
    public function mentionsHotel(string $text, string $hotelName, string $city = ''): bool
    {
        if ($this->appearsInText($text, $hotelName)) {
            return true;
        }

        $core = $this->tokens($hotelName, $city !== '' ? [$city] : []);
        $distinctive = array_filter(array_diff($core, self::WEAK_TOKENS), fn ($t) => mb_strlen($t) >= 4);

        if ($distinctive === []) {
            return false;
        }

        if ($core !== $this->tokens($hotelName) && $this->appearsInText($text, implode(' ', $core))) {
            return true;
        }

        // Leading distinctive words: "Kayon" for "Kayon Jungle Resort",
        // "Four Seasons" for "Four Seasons Resort Bali at Sayan".
        $lead = [];
        foreach ($core as $token) {
            if (in_array($token, self::WEAK_TOKENS, true) || count($lead) === 2) {
                break;
            }
            $lead[] = $token;
        }

        return $lead !== [] && $lead !== $core
            && mb_strlen(implode('', $lead)) >= 4
            && $this->appearsInText($text, implode(' ', $lead));
    }

    /**
     * Whether a website domain carries the hotel's name: "kayonresort.com" or
     * "kayon-jungle-resort.com" for "Kayon Jungle Resort", "alayahotels.co.id"
     * for "Alaya Resort Ubud" (city ignored). Subdomains are ignored, and a
     * name made only of generic words ("Royal Beach") must match in full.
     *
     * @param  list<string>  $ignore
     */
    public function appearsInDomain(string $domain, string $name, array $ignore = []): bool
    {
        $label = $this->siteLabel($domain);

        if ($label === '') {
            return false;
        }

        if (str_contains($label, '-') && $this->matches(str_replace('-', ' ', $label), $name, $ignore)) {
            return true;
        }

        $compact = str_replace('-', '', $label);
        $fullName = implode('', $this->tokens($name));

        if (mb_strlen($fullName) >= 6 && str_contains($compact, $fullName)) {
            return true;
        }

        $core = $this->tokens($name, $ignore);
        $distinctive = array_filter(array_diff($core, self::WEAK_TOKENS), fn ($t) => mb_strlen($t) >= 4);

        if ($distinctive === []) {
            return false;
        }

        $coreText = implode('', $core);

        if (mb_strlen($coreText) >= 5 && str_contains($compact, $coreText)) {
            return true;
        }

        // Leading distinctive words at the start of the domain: "fourseasons" for
        // "Four Seasons Resort Bali at Sayan", "kayon" for "Kayon Jungle Resort".
        $lead = '';
        foreach (array_slice($core, 0, 2) as $token) {
            if (in_array($token, self::WEAK_TOKENS, true)) {
                break;
            }
            $lead .= $token;
        }

        return mb_strlen($lead) >= 5 && str_starts_with($compact, $lead);
    }

    /**
     * The registrable name of a domain without subdomains and TLD:
     * "book.alayahotels.co.id" -> "alayahotels".
     */
    private function siteLabel(string $domain): string
    {
        $host = strtolower(trim($domain));
        $parts = array_values(array_filter(explode('.', $host), fn ($part) => $part !== ''));

        if (count($parts) < 2) {
            return '';
        }

        array_pop($parts);

        $secondLevel = ['co', 'com', 'net', 'org', 'or', 'ac', 'go', 'gov', 'edu', 'web', 'my', 'biz', 'ne'];
        if (count($parts) >= 2 && in_array(end($parts), $secondLevel, true)) {
            array_pop($parts);
        }

        return preg_replace('/[^a-z0-9-]/', '', (string) end($parts)) ?? '';
    }

    /**
     * CJK names: the same name, or one contained in the other ("阿拉亚度假村"
     * in "乌布阿拉亚度假村"), with at least 2 characters. A CJK name never
     * matches a name in Latin letters.
     */
    private function cjkMatches(string $a, string $b): bool
    {
        $ca = $this->compact($a);
        $cb = $this->compact($b);

        if ($ca === '' || $cb === '' || self::isCjk($ca) !== self::isCjk($cb)) {
            return false;
        }

        if ($ca === $cb) {
            return true;
        }

        // Compare the distinctive part: "度假村" (resort) alone must not match every resort.
        $coreA = str_replace(self::CJK_GENERIC, '', $ca);
        $coreB = str_replace(self::CJK_GENERIC, '', $cb);
        [$short, $long] = mb_strlen($coreA) <= mb_strlen($coreB) ? [$coreA, $coreB] : [$coreB, $coreA];

        return mb_strlen($short) >= 2 && str_contains($long, $short);
    }

    /** Lower case, without spaces and punctuation (keeps CJK characters). */
    private function compact(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    /** @return list<string> */
    private function rawTokens(string $value): array
    {
        $value = Str::lower(Str::ascii($value));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace("/['\x{2019}]/u", '', $value);   // "Couple's" -> "couples"
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return array_values(array_filter(explode(' ', trim((string) $value)), fn ($t) => $t !== ''));
    }
}
