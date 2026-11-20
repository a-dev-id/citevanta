<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of copy-ready content for a recommendation (ContentService).
 * body is what was generated; edited_body is the team's edit, if any.
 */
#[Fillable(['hotel_id', 'recommendation_id', 'created_by', 'type', 'language', 'version', 'status', 'title', 'body', 'edited_body', 'error'])]
class ContentPiece extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'status' => ContentStatus::class,
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<Recommendation, $this> */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The text to copy: the team's edit, or what was generated. */
    public function text(): string
    {
        return (string) ($this->edited_body ?? $this->body ?? '');
    }
}
