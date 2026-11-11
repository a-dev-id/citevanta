<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class RequestPricing
{
    public function snapshot(string $model): array
    {
        $config = config('spending.pricing');
        if (! is_array($config) || ($config['currency'] ?? null) !== 'USD' || empty($config['version']) || empty($config['valid_until']) || empty($config['verified_on']) || ! $this->validDate($config['verified_on']) || ! $this->validDate($config['valid_until']) || now('UTC')->format('Y-m-d') < $config['verified_on'] || now('UTC')->format('Y-m-d') > $config['valid_until']) {
            $this->unavailable();
        }
        $rates = collect($config['models'] ?? [])->first(fn ($rates) => in_array($model, $rates['aliases'] ?? [], true));
        if (! $rates) {
            $this->unavailable();
        }
        foreach (['input_micros_per_million', 'cached_micros_per_million', 'output_micros_per_million', 'search_micros', 'search_input_tokens', 'context_input_ceiling', 'max_tool_calls', 'max_output_tokens'] as $key) {
            if (! isset($rates[$key]) || ! is_int($rates[$key]) || $rates[$key] < 0) {
                $this->unavailable();
            }
        }
        if ($rates['input_micros_per_million'] === 0 || $rates['output_micros_per_million'] === 0 || $rates['search_micros'] === 0 || $rates['max_tool_calls'] !== 1 || $rates['context_input_ceiling'] < 8000 || $rates['max_output_tokens'] < 1) {
            $this->unavailable();
        }
        if ($rates['require_cache_write_usage'] ?? false) {
            foreach (['cache_write_30m_micros_per_million', 'cache_write_12h_micros_per_million'] as $key) {
                if (! is_int($rates[$key] ?? null) || $rates[$key] < $rates['input_micros_per_million']) {
                    $this->unavailable();
                }
            }
        }

        return ['version' => $config['version'], 'verified_on' => $config['verified_on'], 'valid_until' => $config['valid_until'], 'currency' => 'USD', 'model' => $model, 'rates' => $rates, 'sources' => $config['sources'] ?? []];
    }

    public function quote(string $text, string $model, int $safety): array
    {
        $price = $this->snapshot($model);
        $r = $price['rates'];
        $input = strlen($text) + 2048 + $r['search_input_tokens'];
        if (! is_int(config('intelligence.max_output_tokens')) || config('intelligence.max_output_tokens') < 1) {
            $this->unavailable();
        }
        if ($input > $r['context_input_ceiling'] || config('intelligence.max_output_tokens') > $r['max_output_tokens']) {
            $this->unavailable();
        }
        $estimate = $this->tokens($input, max($r['input_micros_per_million'], $r['cache_write_30m_micros_per_million'] ?? 0)) + $this->tokens(config('intelligence.max_output_tokens'), $r['output_micros_per_million']) + $r['search_micros'];
        $ceiling = $this->tokens($r['context_input_ceiling'], max($r['input_micros_per_million'], $r['cache_write_30m_micros_per_million'] ?? 0, $r['cache_write_12h_micros_per_million'] ?? 0)) + $this->tokens($r['max_output_tokens'], $r['output_micros_per_million']) + $r['search_micros'];

        return ['estimate' => $estimate, 'reserve' => $this->withSafety($ceiling, $safety), 'pricing' => $price];
    }

    public function usage(array $raw, array $price): ?int
    {
        $r = $price['rates'];
        $usage = $raw['usage'] ?? null;
        if (! is_array($usage) || ! is_int($usage['input_tokens'] ?? null) || ! is_int($usage['output_tokens'] ?? null) || $usage['input_tokens'] < 0 || $usage['output_tokens'] < 0 || ! in_array($raw['model'] ?? $price['model'], $r['aliases'], true)) {
            return null;
        }
        $calls = collect($raw['output'] ?? [])->filter(fn ($i) => is_array($i) && ($i['type'] ?? '') === 'web_search_call')->count();
        if (($r['search_input_is_fixed'] ?? true) && $usage['input_tokens'] < $calls * $r['search_input_tokens']) {
            return null;
        }
        $cached = $usage['input_tokens_details']['cached_tokens'] ?? 0;
        if (! is_int($cached) || $cached < 0 || $cached > $usage['input_tokens']) {
            return null;
        }
        $details = $usage['input_tokens_details'] ?? [];
        $writes = $details['cache_write_tokens'] ?? (($r['require_cache_write_usage'] ?? false) ? null : 0);
        if (! is_int($writes) || $writes < 0 || $cached + $writes > $usage['input_tokens']) {
            return null;
        }
        $writeCost = 0;
        $split = $details['cache_write_tokens_details'] ?? null;
        if ($writes > 0 || $split !== null) {
            if (! is_array($split) || ! is_int($split['ttl_30m_tokens'] ?? null) || ! is_int($split['ttl_12h_tokens'] ?? null) || $split['ttl_30m_tokens'] < 0 || $split['ttl_12h_tokens'] < 0 || $writes !== $split['ttl_30m_tokens'] + $split['ttl_12h_tokens']) {
                return null;
            }
        }
        if ($writes > 0) {
            if (! isset($r['cache_write_30m_micros_per_million'], $r['cache_write_12h_micros_per_million'])) {
                return null;
            }
            $writeCost = $this->tokens($split['ttl_30m_tokens'], $r['cache_write_30m_micros_per_million']) + $this->tokens($split['ttl_12h_tokens'], $r['cache_write_12h_micros_per_million']);
        }

        return $this->tokens($usage['input_tokens'] - $cached - $writes, $r['input_micros_per_million']) + $this->tokens($cached, $r['cached_micros_per_million']) + $writeCost + $this->tokens($usage['output_tokens'], $r['output_micros_per_million']) + $calls * $r['search_micros'];
    }

    public function explanationQuote(string $input, string $model, int $safety): array
    {
        $price = $this->snapshot($model);
        $r = $price['rates'];
        $inputTokens = strlen($input) + 4096;
        if ($inputTokens > $r['context_input_ceiling']) {
            $this->unavailable();
        }
        $output = min(1200, $r['max_output_tokens']);
        $estimate = $this->tokens($inputTokens, max($r['input_micros_per_million'], $r['cache_write_30m_micros_per_million'] ?? 0)) + $this->tokens($output, $r['output_micros_per_million']);
        $ceiling = $this->tokens($r['context_input_ceiling'], max($r['input_micros_per_million'], $r['cache_write_30m_micros_per_million'] ?? 0, $r['cache_write_12h_micros_per_million'] ?? 0)) + $this->tokens($output, $r['output_micros_per_million']);

        return ['estimate' => $estimate, 'reserve' => $this->withSafety($ceiling, $safety), 'pricing' => $price, 'max_output_tokens' => $output];
    }

    public function withSafety(int $amount, int $percent): int
    {
        return intdiv($amount * (100 + $percent) + 99, 100);
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function tokens(int $tokens, int $rate): int
    {
        return intdiv($tokens * $rate + 999999, 1000000);
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['cost' => 'A current, complete pricing estimate is unavailable. No request can be started. Review the versioned pricing configuration.']);
    }

    public static function dollars(?int $micros): string
    {
        return $micros === null ? 'Unavailable' : '$'.number_format($micros / 1000000, 2).' USD';
    }

    public static function micros(string $dollars): int
    {
        [$whole,$fraction] = array_pad(explode('.', $dollars, 2), 2, '');

        return (int) $whole * 1000000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }
}
