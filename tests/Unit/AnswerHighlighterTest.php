<?php

namespace Tests\Unit;

use App\Services\Analysis\HotelNameMatcher;
use App\Services\Dashboard\AnswerHighlighter;
use PHPUnit\Framework\TestCase;

class AnswerHighlighterTest extends TestCase
{
    private function highlight(string $answer): string
    {
        return (string) (new AnswerHighlighter(new HotelNameMatcher))
            ->highlight($answer, 'Alaya Resort Ubud', 'Ubud', ['Kayon Jungle Resort']);
    }

    public function test_the_hotel_and_competitors_are_highlighted_differently(): void
    {
        $html = $this->highlight("1. **ALAYA Ubud** - great\n2. **Kayon Jungle Resort** - quiet\n3. **Maya Ubud** - fine");

        $this->assertStringContainsString('<mark class="cv-mark-own">ALAYA Ubud</mark>', $html);
        $this->assertStringContainsString('<mark class="cv-mark-competitor">Kayon Jungle Resort</mark>', $html);
        $this->assertStringContainsString('<strong>Maya Ubud</strong>', $html);
    }

    public function test_plain_text_mentions_are_highlighted_too(): void
    {
        $html = $this->highlight('We loved Alaya Resort Ubud and the kayon jungle resort.');

        $this->assertStringContainsString('<mark class="cv-mark-own">Alaya Resort Ubud</mark>', $html);
        $this->assertStringContainsString('<mark class="cv-mark-competitor">kayon jungle resort</mark>', $html);
    }

    public function test_answer_html_is_escaped(): void
    {
        $html = $this->highlight('<script>alert(1)</script> **<b>Alaya</b>**');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }
}
