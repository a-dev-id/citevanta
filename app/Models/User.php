<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $current_team_id
 * @property bool $is_admin
 * @property bool|null $weekly_report
 * @property int|null $report_page_size rows per page in the Reports tables (10, 50 or 100)
 * @property-read Team|null $currentTeam
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function belongsToTeam(?Team $team): bool
    {
        return $team !== null && $this->teams()->whereKey($team->id)->exists();
    }

    public function teamRole(Team $team): ?TeamRole
    {
        $role = $this->teams()->whereKey($team->id)->first()?->pivot?->role;

        return $role !== null ? TeamRole::tryFrom($role) : null;
    }

    public function ownsTeam(?Team $team): bool
    {
        return $team !== null && $this->teamRole($team) === TeamRole::Owner;
    }

    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill(['current_team_id' => $team->id])->save();
        $this->setRelation('currentTeam', $team);

        return true;
    }

    /**
     * Whether the user gets the weekly email report for a team: their own
     * choice, otherwise on for owners and off for members.
     */
    public function wantsWeeklyReport(Team $team): bool
    {
        return $this->weekly_report ?? $this->ownsTeam($team);
    }

    /**
     * Get the user's initials, e.g. "Jane Doe" -> "JD".
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->pipe(fn ($letters) => $letters->count() > 1 ? $letters->first().$letters->last() : (string) $letters->first());
    }

    /**
     * @return array<string, string>
     */
    /** @return HasMany<UserTour, $this> */
    public function tours(): HasMany
    {
        return $this->hasMany(UserTour::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'weekly_report' => 'boolean',
            'report_page_size' => 'integer',
        ];
    }
}
