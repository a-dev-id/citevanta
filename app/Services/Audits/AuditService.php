<?php

namespace App\Services\Audits;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Enums\AiPurpose;
use App\Enums\Persona;
use App\Enums\PromptSource;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\RunAuditJob;
use App\Mail\AuditReady;
use App\Models\AiCall;
use App\Models\Audit;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\AiSpend;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetGuard;
use App\Services\Engines\EngineManager;
use App\Services\Prompts\PromptContext;
use App\Services\Prompts\PromptGenerator;
use App\Services\Support\Url;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Free AI visibility audits (website lead magnet):
 *  - limits: per IP and per email per day, and a daily global budget of new audits
 *  - the same hotel + city audited in the last 7 days reuses that report
 *    (a new lead row pointing at it, no new AI calls)
 *  - a new audit creates a hidden, team-less hotel with 10 English prompts and
 *    a scan run (1 run, ChatGPT only) that RunAuditJob runs on the "audits" queue
 */
final class AuditService
{
    public function __construct(
        private readonly PromptGenerator $prompts,
        private readonly EngineManager $engines,
    ) {}

    /**
     * @param  array{hotel_name: string, city: string, country: string, website?: ?string, email: string, consent?: bool, locale?: string}  $input
     *
     * @throws AuditNotAllowed
     */
    public function request(array $input, string $ip): Audit
    {
        $email = Str::lower(trim($input['email']));
        $ipKey = 'audit-ip:'.sha1($ip);
        $emailKey = 'audit-email:'.sha1($email);

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('citevanta.audit.per_ip_per_day', 3))
            || RateLimiter::tooManyAttempts($emailKey, (int) config('citevanta.audit.per_email_per_day', 3))) {
            throw AuditNotAllowed::tooMany();
        }

        $attributes = [
            'hotel_name' => trim($input['hotel_name']),
            'city' => trim($input['city']),
            'country' => trim($input['country']),
            'website' => Url::normalize($input['website'] ?? null),
            'email' => $email,
            'consent' => (bool) ($input['consent'] ?? false),
            'locale' => in_array($input['locale'] ?? 'en', ['en', 'id'], true) ? $input['locale'] : 'en',
            'ip_hash' => hash('sha256', $ip.config('app.key')),
            'dedupe_key' => self::dedupeKey($input['hotel_name'], $input['city']),
        ];

        $source = $this->recentAudit($attributes['dedupe_key']);

        if ($source === null && ($this->newAuditsToday() >= (int) config('citevanta.audit.daily_budget', 5) || ! $this->moneyLeft())) {
            throw AuditNotAllowed::budget();
        }

        RateLimiter::hit($ipKey, 86400);
        RateLimiter::hit($emailKey, 86400);

        if ($source !== null) {
            $audit = Audit::create($attributes + ['token' => self::token(), 'source_audit_id' => $source->id, 'status' => $source->status]);

            if ($source->status === Audit::COMPLETED) {
                $this->sendReport($audit);
            }

            return $audit;
        }

        return $this->start($attributes);
    }

    /**
     * Whether the audits budget (AI_BUDGET_AUDITS) and the total still have
     * room for a whole audit (prompts x answer + analysis, with the margin).
     */
    public function moneyLeft(): bool
    {
        $spend = app(AiSpend::class);
        $estimate = $spend->estimateCalls((int) config('citevanta.audit.prompts', 10));

        try {
            app(BudgetGuard::class)->authorize(new AiCallContext(AiBucket::Audits), AiCallKind::Answer);
        } catch (BudgetExceeded) {
            return false;
        }

        return $spend->spent(AiBucket::Audits) + $estimate <= $spend->cap(AiBucket::Audits)
            && $spend->total() + $estimate <= $spend->totalCap();
    }

    public static function dedupeKey(string $hotelName, string $city): string
    {
        $normalize = fn (string $value) => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($value))));

        return Str::limit($normalize($hotelName).'|'.$normalize($city), 191, '');
    }

    /**
     * New audits (that run AI calls) created today, for the daily budget.
     */
    public function newAuditsToday(): int
    {
        return Audit::query()->whereNull('source_audit_id')->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Results after the scan: the audit and every lead that reused it get them,
     * the cost is recorded in ai_calls (purpose "audit") and the report emailed.
     */
    public function complete(Audit $audit, AuditReport $report): void
    {
        $scan = $audit->scanRun;

        DB::transaction(function () use ($audit, $report, $scan): void {
            $audit->update([
                'status' => $report->questions > 0 ? Audit::COMPLETED : Audit::FAILED,
                'questions' => $report->questions,
                'questions_mentioned' => $report->questionsMentioned,
                'visibility' => $report->visibility,
                'recommended_instead' => $report->recommendedInstead,
                'domain_types' => $report->domainTypes,
                'insights' => $report->insights,
                'cost_usd' => (float) ($scan?->cost_usd ?? 0),
                'error' => $report->questions > 0 ? null : 'ChatGPT returned no answers.',
                'finished_at' => now(),
            ]);

            $audit->reuses()->update(['status' => $audit->status]);

            AiCall::query()->updateOrCreate(
                ['scan_run_id' => null, 'purpose' => AiPurpose::Audit->value, 'subject' => 'audit:'.$audit->id],
                [
                    'hotel_id' => $audit->hotel_id,
                    'model' => (string) ($scan?->model ?? ''),
                    'tokens_in' => (int) ($scan?->responses()->sum('tokens_in') ?? 0),
                    'tokens_out' => (int) ($scan?->responses()->sum('tokens_out') ?? 0),
                    'cost_usd' => (float) ($scan?->cost_usd ?? 0),
                    'result' => ['questions' => $report->questions, 'mentioned' => $report->questionsMentioned],
                ],
            );
        });

        if ($audit->status === Audit::COMPLETED) {
            foreach ([$audit->fresh(), ...$audit->reuses()->get()] as $lead) {
                $this->sendReport($lead);
            }
        }
    }

    /**
     * Email the report link once per lead (queued; sent even if the page was closed).
     */
    public function sendReport(Audit $audit): void
    {
        if ($audit->email_sent_at !== null) {
            return;
        }

        Mail::to($audit->email)->queue(new AuditReady($audit));
        $audit->update(['email_sent_at' => now()]);
    }

    /**
     * Registration from an audit report: remember which team the lead became.
     */
    public function markConverted(Audit $audit, Team $team): void
    {
        if ($audit->converted_team_id === null) {
            $audit->update(['converted_team_id' => $team->id, 'converted_at' => now()]);
        }
    }

    /**
     * Audit cost per day (last 30 days) and this month, from ai_calls.
     *
     * @return array{today: float, month: float, days: array<string, float>}
     */
    public function costs(): array
    {
        $calls = AiCall::query()->where('purpose', AiPurpose::Audit->value)->where('created_at', '>=', now()->subDays(30)->startOfDay())->get(['cost_usd', 'created_at']);

        return [
            'today' => round((float) $calls->where('created_at', '>=', now()->startOfDay())->sum('cost_usd'), 6),
            'month' => round((float) AiCall::query()->where('purpose', AiPurpose::Audit->value)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 6),
            'days' => $calls->groupBy(fn ($call) => Carbon::parse($call->created_at)->toDateString())
                ->map(fn ($day) => round((float) $day->sum('cost_usd'), 6))
                ->sortKeysDesc()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function start(array $attributes): Audit
    {
        $audit = DB::transaction(function () use ($attributes): Audit {
            $hotel = Hotel::create([
                'team_id' => null,
                'is_audit' => true,
                'name' => $attributes['hotel_name'],
                'city' => $attributes['city'],
                'country' => $attributes['country'],
                'website' => $attributes['website'],
                'hotel_type' => 'hotel',
            ]);

            $generated = $this->prompts->generate(new PromptContext(
                hotelName: $hotel->name,
                city: $hotel->city,
                country: $hotel->country,
                hotelType: 'hotel',
                language: 'en',
            ), (int) config('citevanta.audit.prompts', 10));

            $promptIds = [];
            foreach ($generated as $prompt) {
                $promptIds[] = $hotel->prompts()->create([
                    'text' => $prompt->text,
                    'persona' => $prompt->persona instanceof Persona ? $prompt->persona : Persona::Leisure,
                    'language' => $prompt->language,
                    'source' => PromptSource::Generated,
                    'is_active' => true,
                ])->id;
            }

            $engine = (string) config('citevanta.audit.engine', 'openai');
            $scan = ScanRun::create([
                'hotel_id' => $hotel->id,
                'engine' => $engine,
                'model' => $this->engines->make($engine)->model(),
                'trigger' => ScanTrigger::Audit,
                'status' => ScanStatus::Queued,
                'prompt_ids' => $promptIds,
                'runs_per_prompt' => 1,
            ]);

            return Audit::create($attributes + [
                'token' => self::token(),
                'hotel_id' => $hotel->id,
                'scan_run_id' => $scan->id,
                'status' => Audit::QUEUED,
            ]);
        });

        RunAuditJob::dispatch($audit->id);

        return $audit;
    }

    private function recentAudit(string $dedupeKey): ?Audit
    {
        return Audit::query()
            ->where('dedupe_key', $dedupeKey)
            ->whereNull('source_audit_id')
            ->whereIn('status', [Audit::QUEUED, Audit::RUNNING, Audit::COMPLETED])
            ->where('created_at', '>=', now()->subDays((int) config('citevanta.audit.reuse_days', 7)))
            ->latest('id')
            ->first();
    }

    /** 48 random letters and digits: not guessable, safe in a URL. */
    private static function token(): string
    {
        do {
            $token = Str::random(48);
        } while (Audit::query()->where('token', $token)->exists());

        return $token;
    }
}
