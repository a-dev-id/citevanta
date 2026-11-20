<?php

namespace App\Services\Budget;

use App\Mail\Budget\OpenAiBillingStopped;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use Throwable;

/**
 * "OpenAI says there is no money": out of credit or a spend/usage limit.
 * Never retried. The first one pauses every queued AI job, emails the
 * admins once and shows a red banner on Admin > System until an admin
 * resumes (after topping up or raising the limit).
 * A normal rate-limit 429 is not a billing stop and keeps its retry rules.
 */
final class BillingStop
{
    public const CODES = [
        'credit_balance_exhausted',
        'organization_spend_limit_exceeded',
        'project_spend_limit_exceeded',
        'organization_usage_limit_exceeded',
        'insufficient_quota',
    ];

    private const KEY = 'citevanta:ai-billing-stop';

    private const MAIL_KEY = 'citevanta:ai-billing-stop-mailed';

    public function __construct(
        private readonly BudgetPauser $pauser,
        private readonly AdminMailer $admins,
    ) {}

    public static function errorCode(Throwable $e): ?string
    {
        if ($e instanceof ErrorException) {
            $codes = [(string) $e->getErrorCode(), (string) $e->getErrorType()];
        } elseif ($e instanceof RateLimitException) {
            $body = json_decode((string) $e->response->getBody(), true);
            $codes = [(string) ($body['error']['code'] ?? ''), (string) ($body['error']['type'] ?? '')];
        } else {
            return null;
        }

        foreach ($codes as $code) {
            if (in_array($code, self::CODES, true)) {
                return $code;
            }
        }

        return null;
    }

    public static function isBillingError(Throwable $e): bool
    {
        return self::errorCode($e) !== null;
    }

    public function isActive(): bool
    {
        return cache()->has(self::KEY);
    }

    /**
     * @return array{code: string, at: string}|null
     */
    public function info(): ?array
    {
        $info = cache()->get(self::KEY);

        return is_array($info) ? $info : null;
    }

    public function trigger(Throwable $e): void
    {
        $code = self::errorCode($e) ?? 'unknown';
        cache()->forever(self::KEY, ['code' => $code, 'at' => now()->toIso8601String()]);

        $this->pauser->pauseAll(BudgetExceeded::BILLING_STOP);

        if (cache()->add(self::MAIL_KEY, true, now()->addDays(30))) {
            $this->admins->send(fn () => new OpenAiBillingStopped($code));
        }
    }

    public function clear(): void
    {
        cache()->forget(self::KEY);
        cache()->forget(self::MAIL_KEY);
    }
}
