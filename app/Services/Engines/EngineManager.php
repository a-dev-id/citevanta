<?php

namespace App\Services\Engines;

use InvalidArgumentException;
use OpenAI\Contracts\ClientContract;

/**
 * Creates engines by key and applies the CITEVANTA_ENGINE switch:
 * "fake" serves every engine slot with a FakeEngine (no API calls),
 * "openai" uses the real implementations.
 */
final class EngineManager
{
    /** Engine keys that have a real implementation today. */
    public const IMPLEMENTED = ['openai'];

    public function isFake(): bool
    {
        return config('citevanta.engine') !== 'openai';
    }

    /**
     * Engines a plan allows that actually exist.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function usable(array $allowed): array
    {
        return array_values(array_intersect(self::IMPLEMENTED, $allowed));
    }

    /** @var array<string, EngineContract> */
    private array $swapped = [];

    /**
     * Replace an engine (tests). The manager is a singleton, so jobs see it too.
     */
    public function swap(string $engine, EngineContract $instance): void
    {
        $this->swapped[$engine] = $instance;
    }

    /**
     * @param  string  $tier  OpenAiLimits::FREE (free audits, trials) or OpenAiLimits::PAID
     */
    public function make(string $engine, string $tier = OpenAiLimits::PAID): EngineContract
    {
        if (isset($this->swapped[$engine])) {
            return $this->swapped[$engine];
        }

        if (! in_array($engine, self::IMPLEMENTED, true)) {
            throw new InvalidArgumentException("Engine [{$engine}] is not implemented.");
        }

        if ($this->isFake()) {
            return new FakeEngine(
                engineName: $engine,
                mentionRate: (float) config('citevanta.fake.mention_rate', 0.45),
                competitorRate: (float) config('citevanta.fake.competitor_rate', 0.5),
                salt: (string) config('citevanta.fake.salt', ''),
            );
        }

        return match ($engine) {
            'openai' => new OpenAiEngine(
                client: app(ClientContract::class),
                model: (string) config('citevanta.scan_model'),
                webSearchTool: (string) config('citevanta.web_search.tool'),
                toolChoice: (string) config('citevanta.web_search.tool_choice'),
                limits: OpenAiLimits::scan((string) config('citevanta.web_search.tool'), $tier),
            ),
        };
    }
}
