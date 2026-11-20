<?php

namespace Tests\Unit;

use App\Services\Analysis\HotelNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HotelNameMatcherTest extends TestCase
{
    public static function matchingNames(): array
    {
        return [
            'case' => ['ALAYA RESORT UBUD', 'Alaya Resort Ubud'],
            'drops Hotel/Resort' => ['The Kayon Resort', 'Kayon'],
            'drops & Spa and punctuation' => ['Ubud Hanging Gardens Resort & Spa', 'Ubud Hanging Gardens'],
            'drops city' => ['Alaya Resort Ubud', 'Alaya'],
            'accents' => ['Hôtel Plaza Athénée', 'Plaza Athenee'],
            'apostrophes' => ["Kamandalu's Resort", 'Kamandalus'],
            'fuller name' => ['Mandapa, a Ritz-Carlton Reserve', 'Mandapa'],
            'hyphen vs space' => ['Ritz-Carlton Bali', 'Ritz Carlton Bali'],
            'small typo' => ['Komaneka at Bisma', 'Komaneka Bismaa'],
            'brand plus generic word' => ['Grand Hyatt', 'Grand Hyatt Bali'],
        ];
    }

    #[DataProvider('matchingNames')]
    public function test_it_matches_variants_of_the_same_hotel(string $a, string $b): void
    {
        $this->assertTrue((new HotelNameMatcher)->matches($a, $b, ['Ubud']), "$a ~ $b");
    }

    public static function differentNames(): array
    {
        return [
            ['Four Seasons Resort Bali at Sayan', 'Four Points by Sheraton'],
            ['Alaya Resort Ubud', 'Maya Ubud Resort'],
            ['Hotel Indigo', 'Hotel Tugu'],
            ['The Ubud Village Resort', 'Ubud Village Hotel Nirwana'],
            ['Spa Hotel', 'Resort'],
        ];
    }

    #[DataProvider('differentNames')]
    public function test_it_does_not_match_different_hotels(string $a, string $b): void
    {
        $this->assertFalse((new HotelNameMatcher)->matches($a, $b, ['Ubud']), "$a !~ $b");
    }

    public function test_name_made_only_of_city_and_generic_words_keeps_the_city(): void
    {
        $matcher = new HotelNameMatcher;

        $this->assertSame('ubud', $matcher->normalize('The Ubud Hotel', ['Ubud']));
        $this->assertTrue($matcher->matches('Ubud Hotel', 'The Ubud Hotel', ['Ubud']));
    }

    public function test_it_finds_names_in_free_text_as_whole_words(): void
    {
        $matcher = new HotelNameMatcher;
        $text = 'For families, the Alaya Resort in Ubud is great; Kayon Jungle is quieter.';

        $this->assertTrue($matcher->appearsInText($text, 'ALAYA Resort Ubud', ['Ubud']));
        $this->assertTrue($matcher->appearsInText($text, 'The Kayon Jungle Resort', ['Ubud']));
        $this->assertFalse($matcher->appearsInText($text, 'Alam Resort', ['Ubud']));
        $this->assertFalse($matcher->appearsInText('Kayonix is new', 'Kayon', ['Ubud']));
    }

    public static function promptsNamingTheHotel(): array
    {
        return [
            'full name' => ['Is Alaya Resort Ubud good for families?', 'Alaya Resort Ubud'],
            'core without city' => ['Is the alaya worth it?', 'Alaya Resort Ubud'],
            'leading word' => ['How is Kayon for a honeymoon?', 'Kayon Jungle Resort'],
            'brand pair' => ['Is Four Seasons the best in Ubud?', 'Four Seasons Resort Bali at Sayan'],
        ];
    }

    #[DataProvider('promptsNamingTheHotel')]
    public function test_mentions_hotel_catches_the_hotels_name_in_a_prompt(string $prompt, string $hotel): void
    {
        $this->assertTrue((new HotelNameMatcher)->mentionsHotel($prompt, $hotel, 'Ubud'));
    }

    public static function promptsNotNamingTheHotel(): array
    {
        return [
            'city only' => ['What is the best resort in Ubud?', 'Alaya Resort Ubud'],
            'generic word of the name' => ['Which village near Ubud is quietest?', 'Ubud Village Resort'],
            'common word that starts a brand' => ['Four nights in Ubud: which resort?', 'Four Seasons Resort Bali at Sayan'],
            'similar but different word' => ['Is Alayan cuisine good in Ubud?', 'Alaya Resort Ubud'],
        ];
    }

    #[DataProvider('promptsNotNamingTheHotel')]
    public function test_mentions_hotel_allows_prompts_that_do_not_name_it(string $prompt, string $hotel): void
    {
        $this->assertFalse((new HotelNameMatcher)->mentionsHotel($prompt, $hotel, 'Ubud'));
    }

    public function test_index_in_returns_first_match_position(): void
    {
        $names = ['Maya Ubud', 'Alaya Resort Ubud', 'Alaya Ubud'];

        $this->assertSame(1, (new HotelNameMatcher)->indexIn($names, 'Alaya', ['Ubud']));
        $this->assertNull((new HotelNameMatcher)->indexIn($names, 'Kayon', ['Ubud']));
    }

    public static function cjkNames(): array
    {
        return [
            'same Chinese name' => ['阿拉亚度假村', '阿拉亚度假村', true],
            'Chinese name inside a longer one' => ['乌布阿拉亚度假村', '阿拉亚度假村', true],
            'Chinese name with spaces and punctuation' => ['阿拉亚 · 度假村', '阿拉亚度假村', true],
            'Korean' => ['알라야 리조트 우붓', '알라야 리조트', true],
            'Japanese' => ['アラヤ・リゾート', 'アラヤリゾート', true],
            'generic "resort" alone is not a match' => ['度假村', '阿拉亚度假村', false],
            'generic "hotel" alone is not a match' => ['호텔', '알라야 호텔', false],
            'different Chinese hotels' => ['科莫乌玛度假村', '阿拉亚度假村', false],
            'Chinese never matches Latin' => ['阿拉亚度假村', 'Alaya Resort Ubud', false],
        ];
    }

    #[DataProvider('cjkNames')]
    public function test_chinese_korean_and_japanese_names_are_compared_as_whole_strings(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, (new HotelNameMatcher)->matches($a, $b, ['Ubud']));
    }

    public function test_a_cjk_name_is_found_in_text_without_spaces(): void
    {
        $matcher = new HotelNameMatcher;

        $this->assertTrue($matcher->appearsInText('推荐入住阿拉亚度假村，泳池很漂亮。', '阿拉亚度假村'));
        $this->assertFalse($matcher->appearsInText('推荐入住科莫乌玛度假村。', '阿拉亚度假村'));
    }
}
