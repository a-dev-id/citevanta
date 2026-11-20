<?php

namespace App\Services\Budget;

use App\Enums\AiBucket;
use App\Mail\Budget\BudgetAlert;
use App\Mail\Budget\TeamAiBudgetAlert;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\TeamAiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the admins once per month when a bucket or the total passes 50%,
 * 80% and 100% (budget_alerts remembers what was sent).
 */
final class BudgetAlerts
{
    public const TOTAL = 'total';

    public function __construct(
        private readonly AiSpend $spend,
        private readonly AdminMailer $admins,
    ) {}

    /**
     * A private-plan team's own key: email its owners once at 50%, 80% and
     * 100% of the monthly budget they set (Settings > AI key).
     */
    public function checkTeam(Team $team): void
    {
        $budget = (float) $team->ai_budget_usd;
        $spent = app(TeamAiKey::class)->spentThisMonth($team);
        $percent = $budget > 0 ? $spent / $budget * 100 : 100;

        foreach ((array) config('citevanta.budget.alert_percents', [50, 80, 100]) as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $inserted = DB::table('budget_alerts')->insertOrIgnore([
                'month' => $this->spend->monthKey(),
                'bucket' => 't'.$team->id,
                'percent' => (int) $threshold,
                'created_at' => now(),
            ]);

            if ($inserted > 0) {
                $team->owners()->get()->each(fn (User $owner) => Mail::to($owner)->queue(new TeamAiBudgetAlert($team, (int) $threshold, $spent, $budget)));
            }
        }
    }

    public function check(): void
    {
        $rows = [self::TOTAL => [$this->spend->total(), $this->spend->totalCap()]];

        foreach (AiBucket::cases() as $bucket) {
            $rows[$bucket->value] = [$this->spend->spent($bucket), $this->spend->cap($bucket)];
        }

        foreach ($rows as $bucket => [$spent, $cap]) {
            $percent = $cap > 0 ? $spent / $cap * 100 : ($spent > 0 ? 100 : 0);

            foreach ((array) config('citevanta.budget.alert_percents', [50, 80, 100]) as $threshold) {
                if ($percent < $threshold) {
                    continue;
                }

                $inserted = DB::table('budget_alerts')->insertOrIgnore([
                    'month' => $this->spend->monthKey(),
                    'bucket' => $bucket,
                    'percent' => (int) $threshold,
                    'created_at' => now(),
                ]);

                if ($inserted > 0) {
                    $label = $bucket === self::TOTAL ? 'Total AI budget' : AiBucket::from($bucket)->label();
                    $this->admins->send(fn () => new BudgetAlert($label, (int) $threshold, $spent, $cap));
                }
            }
        }
    }
}
