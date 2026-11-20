<?php

namespace App\Services\Support;

use App\Services\Budget\BillingStop;
use App\Services\Budget\BudgetExceeded;
use OpenAI\Exceptions\ErrorException;
use Throwable;

/**
 * Retries flaky API calls with backoff (config: citevanta.retry).
 * Client errors other than 429 are not retried: a bad key or bad request
 * will fail the same way every time.
 */
final class ApiRetry
{
    /**
     * @param  list<int>  $backoffMs  wait before attempt 2, 3, ...
     */
    public function __construct(
        private readonly int $attempts = 3,
        private readonly array $backoffMs = [2000, 6000],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            attempts: max(1, (int) config('citevanta.retry.attempts', 3)),
            backoffMs: array_values(array_map('intval', (array) config('citevanta.retry.backoff_ms', [2000, 6000]))),
        );
    }

    /**
     * @template T
     *
     * @param  callable(int): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $backoff = $this->backoffMs;

        return retry(
            $this->attempts,
            $callback,
            fn (int $attempt) => $backoff[$attempt - 1] ?? (end($backoff) ?: 0),
            fn (Throwable $e) => self::isRetryable($e),
        );
    }

    public static function isRetryable(Throwable $e): bool
    {
        // Out of credit or over a spend limit: retrying only repeats the refusal.
        if ($e instanceof BudgetExceeded || BillingStop::isBillingError($e)) {
            return false;
        }

        if ($e instanceof ErrorException) {
            $status = $e->getStatusCode();

            return $status === 429 || $status >= 500;
        }

        return true;
    }
}
