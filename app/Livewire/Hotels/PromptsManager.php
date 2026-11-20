<?php

namespace App\Livewire\Hotels;

use App\Enums\Persona;
use App\Livewire\Concerns\MapsValidationErrors;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Services\Dashboard\DashboardService;
use App\Services\Hotels\PromptService;
use App\Services\Plans\PlanGuard;
use App\Services\Prompts\TemplatePromptGenerator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Step d: the traveller prompts. Generated on first entry; rows save when a
 * field changes. In the wizard the footer finishes setup.
 */
class PromptsManager extends Component
{
    use MapsValidationErrors;

    #[Locked]
    public int $hotelId;

    #[Locked]
    public bool $wizard = false;

    /** @var array<int, array{text: string, persona: string, language: string, is_active: bool}> */
    public array $rows = [];

    public string $newText = '';

    public string $newPersona = 'leisure';

    public string $newLanguage = 'en';

    /** Language for "Add prompts in". */
    public string $generateLanguage = '';

    public ?string $notice = null;

    public function mount(int $hotelId, bool $wizard = false): void
    {
        $this->hotelId = $hotelId;
        $this->wizard = $wizard;

        app(PromptService::class)->ensureGenerated($this->hotel());
        $this->loadRows();
    }

    /**
     * Save a row when one of its fields changes ("rows.12.text").
     */
    public function updatedRows(mixed $value, string $key): void
    {
        [$id, $field] = explode('.', $key) + [1 => null];
        $prompt = $this->prompt((int) $id);
        $this->resetErrorBag("rows.{$id}.{$field}");

        $updated = $this->withMappedErrors(
            fn () => app(PromptService::class)->update($prompt, [$field => $value]),
            "rows.{$id}.",
        );

        if ($updated === null && $field === 'is_active') {
            // Keep the toggle in sync with what was actually saved.
            $this->rows[$id]['is_active'] = $prompt->fresh()->is_active;
        }

        if ($updated !== null) {
            $this->rows[$id] = $this->rowFor($updated);
        }
    }

    public function add(PromptService $prompts): void
    {
        $this->resetErrorBag(['newText', 'newPersona', 'newLanguage']);

        $added = $this->withMappedErrors(
            fn () => $prompts->add($this->hotel(), [
                'text' => $this->newText,
                'persona' => $this->newPersona,
                'language' => $this->newLanguage,
            ]),
            ['text' => 'newText', 'persona' => 'newPersona', 'language' => 'newLanguage', 'is_active' => 'newText'],
        );

        if ($added !== null) {
            $this->reset('newText');
            $this->loadRows();
        }
    }

    public function remove(int $id, PromptService $prompts): void
    {
        $prompts->delete($this->prompt($id));
        $this->loadRows();
    }

    public function regenerate(PromptService $prompts): void
    {
        $added = $prompts->regenerate($this->hotel());
        $this->resetErrorBag();
        $this->loadRows();
        $this->notice = "Suggestions refreshed: {$added} generated prompts. Your edited and custom prompts were kept.";
    }

    /**
     * Add generated prompts in one more language (within the plan's language limit).
     */
    public function addLanguage(PromptService $prompts): void
    {
        $this->resetErrorBag('generateLanguage');

        try {
            $added = $prompts->generate($this->hotel(), $this->generateLanguage);
        } catch (ValidationException $e) {
            $this->addError('generateLanguage', $e->validator->errors()->first('language'));

            return;
        }

        $this->loadRows();
        $name = config("citevanta.languages.{$this->generateLanguage}", $this->generateLanguage);
        $this->notice = "{$added} prompts added in {$name}.";
    }

    public function finish(): void
    {
        $this->dispatch('wizard-finish');
    }

    public function back(): void
    {
        $this->dispatch('wizard-back');
    }

    public function render(PromptService $prompts): View
    {
        $hotel = $this->hotel();
        $models = $hotel->prompts()->get()->keyBy('id');

        return view('livewire.hotels.prompts-manager', [
            'groups' => collect($this->rows)
                ->sortBy(fn (array $row) => array_search($row['persona'], array_column(Persona::cases(), 'value'), true))
                ->groupBy('persona', preserveKeys: true),
            'models' => $models,
            'personas' => Persona::cases(),
            'activeCount' => $prompts->activeCount($hotel),
            'limit' => $prompts->limit($hotel),
            'languagesFor' => fn (?Prompt $prompt) => $prompts->allowedLanguages($hotel, $prompt),
            'suggestedLanguages' => $prompts->suggestedLanguages($hotel),
            // Languages with templates that the plan still allows.
            'generatable' => array_intersect_key($prompts->allowedLanguages($hotel), array_flip(TemplatePromptGenerator::supportedLanguages())),
            'languageLimit' => app(PlanGuard::class)->languageLimit($hotel),
            'usedLanguages' => $hotel->prompts()->where('is_active', true)->distinct()->pluck('language')->all(),
            // Outside the wizard: each prompt's visibility in the latest completed scan.
            'visibility' => $this->wizard ? null : app(DashboardService::class)->promptVisibility($hotel),
        ]);
    }

    private function loadRows(): void
    {
        $this->rows = $this->hotel()->prompts()->orderBy('id')->get()
            ->mapWithKeys(fn (Prompt $prompt) => [$prompt->id => $this->rowFor($prompt)])
            ->all();
    }

    /**
     * @return array{text: string, persona: string, language: string, is_active: bool}
     */
    private function rowFor(Prompt $prompt): array
    {
        return [
            'text' => $prompt->text,
            'persona' => $prompt->persona->value,
            'language' => $prompt->language,
            'is_active' => $prompt->is_active,
        ];
    }

    private function prompt(int $id): Prompt
    {
        return $this->hotel()->prompts()->findOrFail($id);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('update', $hotel);

        return $hotel;
    }
}
