<?php

namespace App\Services\Scanning;

use Illuminate\Support\Facades\Log;

/**
 * USD cost from token usage and web search calls, using config/citevanta.php.
 * A model without a configured price is costed at $0 and logged once, so a
 * new model name never breaks a scan.
 */
final class CostCalculator
{
    /** @var array<string, true> models already warned about in this process */
    private static array $warned = [];

    /**
     * @param  array<string, array{input_per_million: float, output_per_million: float}>  $modelPrices
     */
    public function __construct(
        private readonly array $modelPrices,
        private readonly float $webSearchPer1000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            modelPrices: (array) config('citevanta.prices.models', []),
            webSearchPer1000: (float) config('citevanta.prices.web_search_per_1000_calls', 0),
        );
    }

    public function hasPriceFor(string $model): bool
    {
        return $this->priceFor($model) !== null;
    }

    public function tokens(string $model, int $tokensIn, int $tokensOut): float
    {
        $price = $this->priceFor($model);

        if ($price === null) {
            if (($tokensIn > 0 || $tokensOut > 0) && ! isset(self::$warned[$model])) {
                self::$warned[$model] = true;
                Log::warning("CiteVanta: no price configured for model '{$model}'; costed as \$0. Add it to config/citevanta.php (prices.models).");
            }

            return 0.0;
        }

        return $tokensIn / 1_000_000 * (float) $price['input_per_million']
            + $tokensOut / 1_000_000 * (float) $price['output_per_million'];
    }

    public function webSearch(int $calls): float
    {
        return $calls / 1000 * $this->webSearchPer1000;
    }

    /**
     * Exact match first, then the longest configured prefix, so dated
     * snapshots like "gpt-6-luna-2026-10-01" use the "gpt-6-luna" price.
     *
     * @return array{input_per_million: float, output_per_million: float}|null
     */
    private function priceFor(string $model): ?array
    {
        if (isset($this->modelPrices[$model])) {
            return $this->modelPrices[$model];
        }

        $best = null;
        foreach ($this->modelPrices as $name => $price) {
            if (str_starts_with($model, $name.'-') && ($best === null || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }

        return $best !== null ? $this->modelPrices[$best] : null;
    }
}
