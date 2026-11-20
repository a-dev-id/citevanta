<?php

namespace App\Services\Ai;

use Closure;

/**
 * Which OpenAI key the current AI call uses: a team's own key while
 * BudgetGuard runs a call for that team, otherwise ours (null).
 */
final class OpenAiKeyScope
{
    private ?string $key = null;

    public function current(): ?string
    {
        return $this->key;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function using(?string $key, Closure $call): mixed
    {
        $previous = $this->key;
        $this->key = $key;

        try {
            return $call();
        } finally {
            $this->key = $previous;
        }
    }
}
