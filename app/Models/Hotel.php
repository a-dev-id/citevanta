<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\HotelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'team_id', 'is_audit', 'source_audit_id', 'name', 'alternative_names', 'city', 'country', 'website', 'booking_engine_domain', 'hotel_type', 'star_rating',
    'guest_markets', 'setup_step', 'setup_completed_at',
    'currency', 'booking_url', 'rates_description', 'rates_inclusions', 'rates_subdomain', 'rates_published',
])]
class Hotel extends Model
{
    /** @use HasFactory<HotelFactory> */
    use HasFactory;

    /** Wizard steps: details, facts, competitors, prompts. */
    public const SETUP_STEPS = 4;

    protected function casts(): array
    {
        return [
            'star_rating' => 'integer',
            'guest_markets' => 'array',
            'alternative_names' => 'array',
            'is_audit' => 'boolean',
            'rates_published' => 'boolean',
            'setup_step' => 'integer',
            'setup_completed_at' => 'datetime',
        ];
    }

    /**
     * The name plus the other names AI assistants may use (e.g. in Chinese).
     *
     * @return list<string>
     */
    public function allNames(): array
    {
        return array_values(array_unique(array_filter([$this->name, ...(array) ($this->alternative_names ?? [])])));
    }

    protected static function booted(): void
    {
        // A stable public id for the rates pages; never changes when the name does.
        static::creating(function (Hotel $hotel): void {
            $hotel->slug ??= self::uniqueSlug((string) $hotel->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'hotel';
        $slug = $base;

        for ($i = 2; self::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** @return HasMany<RoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<HotelRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(HotelRate::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<HotelFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(HotelFact::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Competitor, $this> */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /** @return HasMany<Prompt, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    /** @return HasMany<ScanRun, $this> */
    public function scanRuns(): HasMany
    {
        return $this->hasMany(ScanRun::class);
    }

    /** @return HasMany<VisibilityScore, $this> */
    public function visibilityScores(): HasMany
    {
        return $this->hasMany(VisibilityScore::class);
    }

    /** @return HasOne<HotelGoogleLink, $this> */
    public function googleLink(): HasOne
    {
        return $this->hasOne(HotelGoogleLink::class);
    }

    /** @return HasMany<AccuracyIssue, $this> */
    public function accuracyIssues(): HasMany
    {
        return $this->hasMany(AccuracyIssue::class);
    }

    /** @return HasMany<Recommendation, $this> */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /** @return HasMany<ContentPiece, $this> */
    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class);
    }

    /** @return HasMany<AiCall, $this> */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }

    /**
     * The most recent scan that finished with results.
     *
     * @return HasOne<ScanRun, $this>
     */
    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(ScanRun::class)->ofMany(
            ['finished_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->whereIn('status', ScanStatus::finishedWithResults()),
        );
    }

    public function isSetUp(): bool
    {
        return $this->setup_completed_at !== null;
    }

    public function hasActiveScan(): bool
    {
        return $this->scanRuns()->whereIn('status', ScanStatus::inProgress())->exists();
    }
}
