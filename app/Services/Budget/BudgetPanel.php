<?php

namespace App\Services\Budget;

use App\Enums\AiBucket;

/**
 * Data for the AI budget panel on Admin > System, and "Resume paused jobs".
 */
final class BudgetPanel
{
    public function __construct(
        private readonly AiSpend $spend,
        private readonly BillingStop $billingStop,
        private readonly BudgetPauser $pauser,
    ) {}

    /**
     * @return array{rows: list<array{label: string, spent: float, cap: float, remaining: float, percent: float}>, billing_stop: ?array{code: string, at: string}, paused: int, can_resume: bool, measured: array<int, array<string, mixed>>, month: string}
     */
    public function data(): array
    {
        $rows = [$this->row('Total', $this->spend->total(), $this->spend->totalCap())];

        foreach ([AiBucket::Paid, AiBucket::Audits, AiBucket::Trials, AiBucket::Admin] as $bucket) {
            $rows[] = $this->row($bucket->label().($bucket === AiBucket::Paid ? ' (rest of total)' : ''), $this->spend->spent($bucket), $this->spend->cap($bucket));
        }

        return [
            'rows' => $rows,
            'billing_stop' => $this->billingStop->info(),
            'paused' => $this->pauser->pausedCount(),
            'can_resume' => $this->canResume(),
            'measured' => [7 => $this->spend->measured(7), 30 => $this->spend->measured(30)],
            'month' => now((string) config('citevanta.budget.timezone'))->format('F Y'),
        ];
    }

    /**
     * There is budget again: the total is under its cap. (A paused job whose
     * bucket is still full pauses again on its first call.)
     */
    public function canResume(): bool
    {
        return $this->spend->total() < $this->spend->totalCap();
    }

    /**
     * Admin "Resume paused jobs": also ends an OpenAI billing stop (after topping up).
     *
     * @throws BudgetExceeded when there is no budget yet
     */
    public function resume(): int
    {
        if (! $this->canResume()) {
            throw new BudgetExceeded(BudgetExceeded::TOTAL, 'The monthly AI budget is still used up. Raise AI_BUDGET_TOTAL first.');
        }

        $this->billingStop->clear();

        return $this->pauser->resumeAll();
    }

    /**
     * @return array{label: string, spent: float, cap: float, remaining: float, percent: float}
     */
    private function row(string $label, float $spent, float $cap): array
    {
        return [
            'label' => $label,
            'spent' => round($spent, 4),
            'cap' => round($cap, 2),
            'remaining' => round(max(0, $cap - $spent), 4),
            'percent' => $cap > 0 ? round($spent / $cap * 100, 1) : ($spent > 0 ? 100.0 : 0.0),
        ];
    }
}
