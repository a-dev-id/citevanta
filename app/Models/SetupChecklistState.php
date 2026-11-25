<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one user left the setup checklist of one team: expanded, minimized or hidden, and how many times the
 * "All set" state was shown (after the second visit it is hidden for good).
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property string $state expanded|minimized|hidden
 * @property int $all_set_views
 */
#[Fillable(['user_id', 'team_id', 'state', 'all_set_views'])]
class SetupChecklistState extends Model
{
    public const EXPANDED = 'expanded';

    public const MINIMIZED = 'minimized';

    public const HIDDEN = 'hidden';

    public const STATES = [self::EXPANDED, self::MINIMIZED, self::HIDDEN];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
