<?php

namespace App\Services\Hotels;

use App\Enums\Persona;
use App\Enums\PromptSource;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Plans\PlanGuard;
use App\Services\Prompts\PromptContext;
use App\Services\Prompts\PromptGenerator;
use App\Services\Prompts\TemplatePromptGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A hotel's traveller prompts: generation, editing and the rules.
 *
 * Rules: at least prompt_min_length characters, never the hotel's own name,
 * no duplicates, at most promptsPerHotel ACTIVE prompts, and at most the
 * plan's number of distinct languages across active prompts.
 */
final class PromptService
{
    public function __construct(
        private readonly PromptGenerator $generator,
        private readonly HotelNameMatcher $matcher,
        private readonly PlanGuard $plans,
    ) {}

    public function limit(Hotel $hotel): int
    {
        return $this->plans->promptLimit($hotel);
    }

    public function activeCount(Hotel $hotel): int
    {
        return $hotel->prompts()->where('is_active', true)->count();
    }

    /**
     * Languages a prompt may use: all configured languages while the plan's
     * language count is not reached, otherwise only those already in use.
     *
     * @return array<string, string> code => name
     */
    public function allowedLanguages(Hotel $hotel, ?Prompt $except = null): array
    {
        $all = (array) config('citevanta.languages', ['en' => 'English']);
        $used = $this->usedLanguages($hotel, $except);

        if (count($used) < $this->plans->languageLimit($hotel)) {
            return $all;
        }

        return array_intersect_key($all, array_flip($used));
    }

    /**
     * Generate suggestions the first time the prompts step is opened.
     */
    public function ensureGenerated(Hotel $hotel): void
    {
        if (! $hotel->prompts()->withTrashed()->exists()) {
            $this->generate($hotel);
        }
    }

    /**
     * Prompt languages suggested by the hotel's guest markets, English first
     * (most international travellers ask AI assistants in English), then each
     * market's language in the order the markets were chosen. Only languages
     * with prompt templates.
     *
     * @return array<string, string> code => name
     */
    public function suggestedLanguages(Hotel $hotel): array
    {
        $map = (array) config('prompts.market_languages', []);
        $codes = collect(['en', ...array_map(fn ($market) => $map[$market] ?? null, (array) ($hotel->guest_markets ?? []))])
            ->filter()
            ->unique()
            ->intersect(TemplatePromptGenerator::supportedLanguages())
            ->values();
        $names = (array) config('citevanta.languages', []);

        return $codes->mapWithKeys(fn ($code) => [$code => $names[$code] ?? strtoupper($code)])->all();
    }

    /**
     * Languages generate() uses: languages already in use first, then the
     * suggested ones, up to the plan's number of languages.
     *
     * @return list<string>
     */
    public function plannedLanguages(Hotel $hotel): array
    {
        $used = $this->usedLanguages($hotel, null);

        return collect([...$used, ...array_keys($this->suggestedLanguages($hotel))])
            ->unique()
            ->intersect(TemplatePromptGenerator::supportedLanguages())
            ->take(max(1, $this->plans->languageLimit($hotel)))
            ->values()->all();
    }

    /**
     * Add generated prompts, skipping any that duplicate an existing prompt.
     * Without a language: every planned language (the first gets the usual
     * count, others config prompts.per_extra_language). New prompts are active
     * while the plan's limit allows, inactive after.
     *
     * @return int number of prompts added
     */
    public function generate(Hotel $hotel, ?string $language = null): int
    {
        if ($language !== null && ! array_key_exists($language, $this->allowedLanguages($hotel))) {
            throw ValidationException::withMessages(['language' => 'Your plan allows '.$this->plans->languageLimit($hotel).' languages. Use one you already use.']);
        }

        $languages = $language !== null ? [$language] : $this->plannedLanguages($hotel);
        $suggestions = [];

        foreach (array_values($languages) as $i => $code) {
            array_push($suggestions, ...$this->generator->generate(new PromptContext(
                hotelName: $hotel->name,
                city: $hotel->city,
                country: $hotel->country,
                hotelType: $hotel->hotel_type,
                language: $code,
                guestMarkets: (array) ($hotel->guest_markets ?? []),
            ), $i === 0 ? 10 : (int) config('prompts.per_extra_language', 6)));
        }

        $existing = $hotel->prompts()->pluck('text')->map(fn ($text) => $this->normalize($text))->all();
        $active = $this->activeCount($hotel);
        $limit = $this->limit($hotel);
        $added = 0;

        foreach ($suggestions as $suggestion) {
            $key = $this->normalize($suggestion->text);

            if (in_array($key, $existing, true) || $this->matcher->mentionsHotel($suggestion->text, $hotel->name, $hotel->city)) {
                continue;
            }

            $hotel->prompts()->create([
                'text' => $suggestion->text,
                'persona' => $suggestion->persona,
                'language' => $suggestion->language,
                'source' => PromptSource::Generated,
                'is_edited' => false,
                'is_active' => $active < $limit,
            ]);

            $existing[] = $key;
            $active += $active < $limit ? 1 : 0;
            $added++;
        }

        return $added;
    }

    /**
     * Replace generated prompts nobody has edited. Edited and custom prompts stay.
     *
     * @return int number of prompts added
     */
    public function regenerate(Hotel $hotel): int
    {
        return DB::transaction(function () use ($hotel): int {
            $hotel->prompts()
                ->where('source', PromptSource::Generated)
                ->where('is_edited', false)
                ->delete();

            return $this->generate($hotel);
        });
    }

    /**
     * @param  array{text?: ?string, persona?: ?string, language?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function add(Hotel $hotel, array $input): Prompt
    {
        $data = $this->validate($hotel, [
            'text' => $input['text'] ?? '',
            'persona' => $input['persona'] ?? '',
            'language' => $input['language'] ?? 'en',
            'is_active' => true,
        ]);

        return $hotel->prompts()->create($data + [
            'source' => PromptSource::Custom,
            'is_edited' => false,
        ]);
    }

    /**
     * Update one prompt. Changing a generated prompt's text, persona or
     * language marks it as edited, so regeneration keeps it.
     *
     * @param  array{text?: ?string, persona?: ?string, language?: ?string, is_active?: bool}  $input
     *
     * @throws ValidationException
     */
    public function update(Prompt $prompt, array $input): Prompt
    {
        $data = $this->validate($prompt->hotel, [
            'text' => $input['text'] ?? $prompt->text,
            'persona' => $input['persona'] ?? $prompt->persona->value,
            'language' => $input['language'] ?? $prompt->language,
            'is_active' => (bool) ($input['is_active'] ?? $prompt->is_active),
        ], $prompt);

        $prompt->fill($data);

        if ($prompt->isDirty(['text', 'persona', 'language'])) {
            $prompt->is_edited = true;
        }

        $prompt->save();

        return $prompt;
    }

    public function delete(Prompt $prompt): void
    {
        $prompt->delete();
    }

    /**
     * @param  array{text: string, persona: string, language: string, is_active: bool}  $input
     * @return array{text: string, persona: Persona, language: string, is_active: bool}
     *
     * @throws ValidationException
     */
    private function validate(Hotel $hotel, array $input, ?Prompt $prompt = null): array
    {
        $text = Str::squish((string) $input['text']);
        $min = (int) config('citevanta.prompt_min_length', 15);
        $errors = [];

        if (mb_strlen($text) < $min) {
            $errors['text'] = "Write at least {$min} characters.";
        } elseif (mb_strlen($text) > 500) {
            $errors['text'] = 'Keep prompts under 500 characters.';
        } elseif ($this->matcher->mentionsHotel($text, $hotel->name, $hotel->city)) {
            $errors['text'] = "Prompts must not contain your hotel's name. We test whether AI recommends it unprompted.";
        } elseif ($this->isDuplicate($hotel, $text, $prompt)) {
            $errors['text'] = 'This prompt is already in the list.';
        }

        $persona = Persona::tryFrom((string) $input['persona']);
        if ($persona === null) {
            $errors['persona'] = 'Choose a persona.';
        }

        $language = (string) $input['language'];
        if (! array_key_exists($language, $this->allowedLanguages($hotel, $prompt))) {
            $errors['language'] = 'Your plan allows '.$this->plans->languageLimit($hotel).' languages. Use one you already use.';
        }

        $wasActive = $prompt !== null && $prompt->is_active;
        if ($input['is_active'] && ! $wasActive && $this->activeCount($hotel) >= $this->limit($hotel)) {
            $errors['is_active'] = 'Your plan allows '.$this->limit($hotel).' active prompts. Deactivate another prompt first.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'text' => $text,
            'persona' => $persona,
            'language' => $language,
            'is_active' => (bool) $input['is_active'],
        ];
    }

    private function isDuplicate(Hotel $hotel, string $text, ?Prompt $except): bool
    {
        $key = $this->normalize($text);

        return $hotel->prompts()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->pluck('text')
            ->contains(fn ($existing) => $this->normalize($existing) === $key);
    }

    /**
     * @return list<string>
     */
    private function usedLanguages(Hotel $hotel, ?Prompt $except): array
    {
        return $hotel->prompts()
            ->where('is_active', true)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->distinct()
            ->pluck('language')
            ->all();
    }

    /**
     * For duplicate checks: case, accents and punctuation ignored. Text in
     * Chinese, Korean or Japanese keeps its characters (ASCII folding would drop them).
     */
    private function normalize(string $text): string
    {
        $text = preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1 ? mb_strtolower($text) : Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
