<?php

namespace App\Services\Ai;

use App\Enums\AiBucket;
use App\Enums\ScanStatus;
use App\Jobs\ScanInsightsJob;
use App\Mail\TeamAiKeyStopped;
use App\Models\AiUsage;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetPauser;
use Illuminate\Support\Facades\Mail;

/**
 * A private-plan team's own OpenAI key: tested before it is saved, stored
 * encrypted, shown only as "sk-...abcd". When OpenAI refuses it (no credit,
 * spend limit, invalid key) only this team's AI work pauses and its owners
 * are emailed; we never fall back to our key.
 */
final class TeamAiKey
{
    /** OpenAI answers that mean the key cannot be used until the owner acts. */
    public const AUTH_CODES = ['invalid_api_key', 'invalid_organization', 'account_deactivated'];

    public function __construct(
        private readonly OpenAiKeyChecker $checker,
        private readonly BudgetPauser $pauser,
    ) {}

    /**
     * @return array{ok: bool, error: ?string, code: ?string}
     */
    public function save(Team $team, string $key): array
    {
        $key = trim($key);
        $result = $this->checker->check($key);

        if ($result['ok']) {
            $team->forceFill([
                'openai_key' => $key,
                'openai_key_last4' => substr($key, -4),
                'openai_key_checked_at' => now(),
                'openai_key_stopped_at' => null,
                'openai_key_stop_code' => null,
            ])->save();
            $this->resume($team);
        }

        return $result;
    }

    /**
     * "Test again" with the saved key.
     *
     * @return array{ok: bool, error: ?string, code: ?string}
     */
    public function test(Team $team): array
    {
        if (! $team->hasOwnAiKey()) {
            return ['ok' => false, 'error' => 'No key saved yet.', 'code' => null];
        }

        $result = $this->checker->check((string) $team->openai_key);
        $team->forceFill(['openai_key_checked_at' => now()])->save();

        if ($result['ok'] && $team->openai_key_stopped_at !== null) {
            $team->forceFill(['openai_key_stopped_at' => null, 'openai_key_stop_code' => null])->save();
            $this->resume($team);
        }

        return $result;
    }

    public function remove(Team $team): void
    {
        $team->forceFill(['openai_key' => null, 'openai_key_last4' => null, 'openai_key_checked_at' => null])->save();
    }

    /**
     * OpenAI refused the team's key: pause the team's work, email the owners once.
     */
    public function stop(Team $team, string $code): void
    {
        $first = $team->openai_key_stopped_at === null;
        $team->forceFill(['openai_key_stopped_at' => now(), 'openai_key_stop_code' => $code])->save();

        ScanRun::query()->whereIn('hotel_id', $team->hotels()->select('id'))
            ->whereIn('status', [ScanStatus::Queued->value, ScanStatus::Pending->value, ScanStatus::Running->value])
            ->get()->each(fn (ScanRun $run) => $this->pauser->pauseScan($run, BudgetExceeded::TEAM_KEY));

        if ($first) {
            $team->owners()->get()->each(fn (User $owner) => Mail::to($owner)->queue(new TeamAiKeyStopped($team, $code)));
        }
    }

    /**
     * Start the team's paused work again (key works, or budget raised).
     */
    public function resume(Team $team): int
    {
        $runs = ScanRun::query()->whereIn('hotel_id', $team->hotels()->select('id'))
            ->where(fn ($query) => $query->where('status', ScanStatus::PausedBudget->value)->orWhereNotNull('insights_paused_at'))
            ->get();

        foreach ($runs as $run) {
            if ($run->status === ScanStatus::PausedBudget) {
                $this->pauser->resumeScan($run);
            } else {
                $run->update(['insights_paused_at' => null]);
                ScanInsightsJob::dispatch($run->id);
            }
        }

        return $runs->count();
    }

    /**
     * What the team spent on its own key this month (Asia/Makassar month).
     */
    public function spentThisMonth(Team $team): float
    {
        $start = now((string) config('citevanta.budget.timezone', 'Asia/Makassar'))->startOfMonth()->utc();

        return (float) AiUsage::query()->where('team_id', $team->id)->where('billed_to_team', true)
            ->where('created_at', '>=', $start)->sum('cost_usd');
    }

    /**
     * What the owner should do, for the banner and the email.
     */
    public static function stopMessage(?string $code): string
    {
        return match (true) {
            in_array($code, self::AUTH_CODES, true) => 'OpenAI does not accept your API key (it may be deleted or wrong). Create a new key in your OpenAI project and paste it in Settings > AI key.',
            $code === 'project_spend_limit_exceeded', $code === 'organization_spend_limit_exceeded' => 'Your OpenAI spend limit is reached. Raise the monthly limit in OpenAI (Settings > Limits), then click "Test again" in Settings > AI key.',
            $code === 'team_budget' => 'Your monthly AI budget in '.config('app.name').' is used up. Raise it in Settings > AI key, or wait until the 1st.',
            default => 'Your OpenAI account has no credit left. Add credit in OpenAI (Settings > Billing), then click "Test again" in Settings > AI key.',
        };
    }

    public static function bucket(): AiBucket
    {
        return AiBucket::Paid;
    }
}
