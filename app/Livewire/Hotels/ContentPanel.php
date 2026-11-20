<?php

namespace App\Livewire\Hotels;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Models\ContentPiece;
use App\Models\Recommendation;
use App\Services\Content\ContentLimitReached;
use App\Services\Content\ContentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Create content" for one recommendation: pick a type and language,
 * generate, browse versions, edit, copy and download. Polls while a piece is
 * being written. Logic lives in ContentService.
 */
class ContentPanel extends Component
{
    #[Locked]
    public int $recommendationId;

    public string $type = '';

    public string $language = 'en';

    /** Version shown (content piece id). */
    public ?int $pieceId = null;

    public string $text = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(int $recommendationId): void
    {
        $recommendation = Recommendation::with('hotel')->findOrFail($recommendationId);
        Gate::authorize('view', $recommendation->hotel);

        $this->recommendationId = $recommendation->id;
        $this->type = $recommendation->category->contentTypes()[0]->value;
        $latest = $recommendation->contentPieces()->latest('id')->first();

        if ($latest !== null) {
            $this->type = $latest->type->value;
            $this->language = $latest->language;
            $this->show($latest->id);
        }
    }

    public function generate(ContentService $content): void
    {
        $recommendation = $this->recommendation();
        Gate::authorize('update', $recommendation->hotel);
        $this->reset('error', 'notice');

        $this->validate([
            'type' => ['required', Rule::enum(ContentType::class)],
            'language' => ['required', Rule::in(array_keys($content->languages($recommendation->hotel)))],
        ]);

        try {
            $piece = $content->request($recommendation, ContentType::from($this->type), $this->language, auth()->user());
            $this->show($piece->id);
        } catch (ContentLimitReached $e) {
            $this->error = $e->getMessage();
        }
    }

    public function show(int $pieceId): void
    {
        $piece = $this->piece($pieceId);
        $this->pieceId = $piece->id;
        $this->text = $piece->text();
    }

    public function saveEdit(ContentService $content): void
    {
        Gate::authorize('update', $this->recommendation()->hotel);
        $this->validate(['text' => ['nullable', 'string', 'max:20000']]);

        $piece = $this->piece((int) $this->pieceId);
        $content->saveEdit($piece, $this->text);
        $this->text = $piece->fresh()->text();
        $this->notice = 'Your edit is saved.';
    }

    public function render(ContentService $content): View
    {
        $recommendation = $this->recommendation();
        $piece = $this->pieceId ? ContentPiece::find($this->pieceId) : null;

        // A piece finished while polling: show its text.
        if ($piece !== null && $piece->status === ContentStatus::Ready && $this->text === '' && $piece->text() !== '') {
            $this->text = $piece->text();
        }

        return view('livewire.hotels.content-panel', [
            'recommendation' => $recommendation,
            'piece' => $piece,
            'versions' => $recommendation->contentPieces()->where('type', $this->type)->where('language', $this->language)->orderByDesc('version')->get(),
            'types' => $recommendation->category->contentTypes(),
            'allTypes' => ContentType::cases(),
            'languages' => $content->languages($recommendation->hotel),
            'allowance' => $content->allowance($recommendation->hotel, auth()->user()),
            'limits' => $piece ? ContentService::limitsFor($piece->type) : [],
        ]);
    }

    private function recommendation(): Recommendation
    {
        $recommendation = Recommendation::with('hotel')->findOrFail($this->recommendationId);
        Gate::authorize('view', $recommendation->hotel);

        return $recommendation;
    }

    private function piece(int $id): ContentPiece
    {
        return $this->recommendation()->contentPieces()->findOrFail($id);
    }
}
