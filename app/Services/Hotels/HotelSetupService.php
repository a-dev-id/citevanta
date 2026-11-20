<?php

namespace App\Services\Hotels;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\HotelFact;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Plans\PlanGuard;
use App\Services\Scanning\ScanLauncher;
use App\Services\Support\Url;
use App\Services\Trials\AuditImporter;
use App\Services\Trials\TrialHotelGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hotel details, facts and the setup wizard's progress.
 *
 * setup_step is the step to resume at (1 details, 2 facts, 3 competitors,
 * 4 prompts). setup_completed_at marks a finished setup.
 */
final class HotelSetupService
{
    public const STEP_DETAILS = 1;

    public const STEP_FACTS = 2;

    public const STEP_COMPETITORS = 3;

    public const STEP_PROMPTS = 4;

    public function __construct(
        private readonly PlanGuard $plans,
        private readonly TrialHotelGuard $trialHotels,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws PlanLimitReached|ValidationException
     */
    public function createHotel(Team $team, array $input): Hotel
    {
        if (! $this->plans->canAddHotel($team)) {
            $limit = $team->limits()->hotels;

            throw new PlanLimitReached(
                'Your '.$team->plan->label().' plan includes '.$limit.' '.Str::plural('hotel', $limit)
                .'. You have reached this limit, so a new hotel was not created.',
            );
        }

        $data = $this->validateDetails($input, $team);

        return Hotel::create($data + [
            'team_id' => $team->id,
            'setup_step' => self::STEP_FACTS,
            // Started from a free audit for this hotel: its answers become the first scan.
            'source_audit_id' => $this->trialHotels->sourceAudit($team, $data['name'], $data['city'])?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateDetails(Hotel $hotel, array $input): Hotel
    {
        $hotel->update($this->validateDetails($input, $hotel->team, $hotel));
        $this->completeStep($hotel, self::STEP_DETAILS);

        return $hotel;
    }

    /**
     * Replace the hotel's facts. Rows without a value are skipped; a value
     * without a key is an error; keys must be unique (case-insensitive).
     *
     * @param  list<array{key: ?string, value: ?string}>  $rows
     */
    public function saveFacts(Hotel $hotel, array $rows): void
    {
        $errors = [];
        $seen = [];
        $facts = [];

        foreach (array_values($rows) as $i => $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($key === '') {
                $errors["facts.{$i}.key"] = 'Add a name for this fact or clear its value.';

                continue;
            }

            if (mb_strlen($key) > 100) {
                $errors["facts.{$i}.key"] = 'Keep the name under 100 characters.';
            }

            if (mb_strlen($value) > 1000) {
                $errors["facts.{$i}.value"] = 'Keep the value under 1000 characters.';
            }

            $normalized = mb_strtolower($key);
            if (isset($seen[$normalized])) {
                $errors["facts.{$i}.key"] = "\"{$key}\" is already listed.";

                continue;
            }

            $seen[$normalized] = true;
            $facts[] = ['key' => $key, 'value' => $value, 'position' => count($facts)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($hotel, $facts): void {
            $hotel->facts()->delete();
            $hotel->facts()->createMany($facts);
        });

        $this->completeStep($hotel, self::STEP_FACTS);
    }

    /**
     * Facts for the form: saved facts, or the suggested keys with empty values.
     *
     * @return list<array{key: string, value: string}>
     */
    public function factRowsFor(Hotel $hotel): array
    {
        $saved = $hotel->facts()->get(['key', 'value'])
            ->map(fn (HotelFact $fact) => ['key' => $fact->key, 'value' => $fact->value])
            ->all();

        if ($saved !== []) {
            return $saved;
        }

        return array_map(fn (string $key) => ['key' => $key, 'value' => ''], HotelFact::suggestedKeys());
    }

    /**
     * Remember that a step is done, so the wizard resumes at the next one.
     */
    public function completeStep(Hotel $hotel, int $step): void
    {
        $next = min(self::STEP_PROMPTS, $step + 1);

        if (! $hotel->isSetUp() && $hotel->setup_step < $next) {
            $hotel->update(['setup_step' => $next]);
        }
    }

    /**
     * Finish setup and queue the first scan. The first scan is recorded with
     * ScanTrigger::Setup, so it never uses the manual scan allowance. A hotel
     * from a free audit gets the audit's results as its first scan instead.
     *
     * @throws ValidationException when the hotel has no active prompt
     */
    public function finish(Hotel $hotel, User $user): ScanRun
    {
        if (! $hotel->prompts()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'prompts' => 'Activate at least one prompt before finishing setup.',
            ]);
        }

        return DB::transaction(function () use ($hotel, $user): ScanRun {
            $hotel->update([
                'setup_step' => self::STEP_PROMPTS,
                'setup_completed_at' => $hotel->setup_completed_at ?? now(),
            ]);

            $running = $hotel->scanRuns()->whereIn('status', ScanStatus::inProgress())->first();

            if ($running !== null) {
                return $running;
            }

            // From a free audit: its answers are the first scan, no new AI calls.
            $importer = app(AuditImporter::class);
            if (($auditScan = $importer->sourceScan($hotel)) !== null) {
                return $importer->import($hotel, $auditScan);
            }

            return app(ScanLauncher::class)->queue($hotel, ScanTrigger::Setup, $user);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateDetails(array $input, ?Team $team, ?Hotel $hotel = null): array
    {
        $input['website'] = Url::normalize($input['website'] ?? null);
        $input['star_rating'] = ($input['star_rating'] ?? '') === '' ? null : $input['star_rating'];
        $input['guest_markets'] = array_values(array_filter((array) ($input['guest_markets'] ?? [])));
        // One name per line (or comma separated), e.g. the name written in Chinese.
        $input['alternative_names'] = array_values(array_unique(array_filter(array_map('trim',
            is_array($input['alternative_names'] ?? null) ? $input['alternative_names'] : (preg_split('/[\r\n,，、]+/u', (string) ($input['alternative_names'] ?? '')) ?: [])
        ), fn ($name) => $name !== '')));

        $countries = array_values(config('countries'));

        $data = Validator::make($input, [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('hotels')
                    ->where(fn ($query) => $query->where('team_id', $team?->id)->where('city', trim((string) ($input['city'] ?? ''))))
                    ->ignore($hotel?->id),
            ],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', Rule::in($countries)],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'hotel_type' => ['required', Rule::in(config('citevanta.hotel_types'))],
            'star_rating' => ['nullable', 'integer', 'between:1,5'],
            'guest_markets' => ['array', 'max:'.(int) config('citevanta.max_guest_markets', 5)],
            'guest_markets.*' => ['string', 'distinct', Rule::in($countries)],
            'alternative_names' => ['array', 'max:5'],
            'alternative_names.*' => ['string', 'min:2', 'max:100'],
        ], [
            'alternative_names.max' => 'Add up to :max other names.',
            'alternative_names.*.min' => 'Each other name needs at least :min characters.',
            'name.unique' => 'Your team already has a hotel with this name in this city.',
            'guest_markets.max' => 'Choose up to :max guest markets.',
            'country.in' => 'Choose a country from the list.',
        ])->validate();

        $data['name'] = trim($data['name']);
        $data['city'] = trim($data['city']);

        if ($team !== null && $this->trialHotels->blocks($team, $data['name'], $data['city'], $hotel)) {
            throw ValidationException::withMessages(['name' => TrialHotelGuard::MESSAGE]);
        }

        return $data;
    }
}
