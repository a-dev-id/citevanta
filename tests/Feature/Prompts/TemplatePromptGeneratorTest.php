<?php

namespace Tests\Feature\Prompts;

use App\Enums\Persona;
use App\Services\Prompts\PromptContext;
use App\Services\Prompts\TemplatePromptGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemplatePromptGeneratorTest extends TestCase
{
    public function test_it_generates_ten_prompts_spread_across_all_personas(): void
    {
        $prompts = (new TemplatePromptGenerator)->generate(
            new PromptContext('Alaya Resort Ubud', 'Ubud', 'Indonesia', 'Resort'),
        );

        $this->assertCount(10, $prompts);

        $perPersona = array_count_values(array_map(fn ($p) => $p->persona->value, $prompts));
        foreach (Persona::cases() as $persona) {
            $this->assertSame(2, $perPersona[$persona->value] ?? 0, $persona->value);
        }

        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('Ubud', $prompt->text);
            $this->assertStringNotContainsStringIgnoringCase('Alaya', $prompt->text);
            $this->assertStringNotContainsString('{', $prompt->text);
            $this->assertSame('en', $prompt->language);
        }

        $this->assertStringContainsString('resort', $prompts[0]->text);
    }

    public function test_smaller_counts_still_cover_every_persona(): void
    {
        // Single-letter name also proves the guard matches whole words only ("x" in "exploring").
        $prompts = (new TemplatePromptGenerator)->generate(new PromptContext('X', 'Paris', 'France'), 5);

        $this->assertCount(5, $prompts);
        $this->assertCount(5, array_unique(array_map(fn ($p) => $p->persona->value, $prompts)));
        $this->assertStringContainsString('hotel', $prompts[0]->text);
    }

    public function test_unsupported_language_falls_back_to_english(): void
    {
        $prompts = (new TemplatePromptGenerator)->generate(new PromptContext('X', 'Paris', 'France', language: 'xx'));

        $this->assertSame('en', $prompts[0]->language);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function languages(): array
    {
        return [
            'Indonesian' => ['id', 'Resort paling romantis di Ubud untuk bulan madu?'],
            'Chinese' => ['zh', 'Ubud最浪漫的蜜月度假村是哪家？'],
            'Korean' => ['ko', 'Ubud 신혼여행으로 로맨틱한 리조트 어디가 좋을까요?'],
            'Japanese' => ['ja', 'Ubudでハネムーンにぴったりのロマンチックなリゾートはどこですか？'],
        ];
    }

    #[DataProvider('languages')]
    public function test_each_language_has_natural_prompts_for_every_persona_with_the_hotel_type_translated(string $language, string $honeymoon): void
    {
        $prompts = (new TemplatePromptGenerator)->generate(new PromptContext('Alaya Resort Ubud', 'Ubud', 'Indonesia', 'resort', $language));

        $this->assertCount(10, $prompts);
        $this->assertSame([$language], array_values(array_unique(array_map(fn ($p) => $p->language, $prompts))));
        $this->assertSame(Persona::cases(), array_values(array_unique(array_map(fn ($p) => $p->persona, $prompts), SORT_REGULAR)));
        $this->assertContains($honeymoon, array_map(fn ($p) => $p->text, $prompts));
        $this->assertSame([], array_filter($prompts, fn ($p) => str_contains($p->text, '{')), 'every placeholder is filled');
    }

    public function test_market_prompts_only_in_languages_where_the_country_name_reads_naturally(): void
    {
        $context = fn (string $language) => new PromptContext('Alaya', 'Ubud', 'Indonesia', 'resort', $language, ['Australia']);

        $this->assertCount(11, (new TemplatePromptGenerator)->generate($context('id')));
        $this->assertCount(10, (new TemplatePromptGenerator)->generate($context('zh')));
    }

    public function test_prompts_containing_the_hotel_name_are_skipped(): void
    {
        // A hotel named after its city would leak its name into every prompt.
        $prompts = (new TemplatePromptGenerator)->generate(new PromptContext('Ubud', 'Ubud', 'Indonesia'));

        $this->assertSame([], $prompts);
    }
}
