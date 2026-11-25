<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's state for one guided tour: the version they saw and whether they finished or skipped it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $tour
 * @property int $version
 * @property string $status finished|skipped
 * @property int $skips how many times the tour was skipped without "Don't show this again"
 * @property CarbonInterface|null $finished_at
 */
#[Fillable(['user_id', 'tour', 'version', 'status', 'skips', 'finished_at'])]
class UserTour extends Model
{
    public const FINISHED = 'finished';

    public const SKIPPED = 'skipped';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }
}
