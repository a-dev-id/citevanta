<?php

namespace Tests\Fakes;

use App\Services\Engines\AskContext;
use App\Services\Engines\CitationData;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineResult;
use Closure;

/** Offline engine for tests: never calls a real API. */
final class StubEngine implements EngineContract
{
    /** @var list<string> */
    public array $prompts = [];

    /**
     * @param  Closure(string $prompt, int $call): (EngineResult|string)  $answer
     *                                                                             Return a string or EngineResult; throw to simulate API errors.
     */
    public function __construct(private readonly Closure $answer, private readonly string $model = 'fake-model') {}

    public static function answering(string $text, array $citationUrls = []): self
    {
        return new self(fn () => new EngineResult(
            text: $text,
            citations: array_map(fn ($u) => CitationData::fromUrl($u), $citationUrls),
            tokensIn: 1000,
            tokensOut: 500,
            webSearchCalls: 1,
        ));
    }

    public function ask(string $prompt, ?AskContext $context = null): EngineResult
    {
        $this->prompts[] = $prompt;
        $result = ($this->answer)($prompt, count($this->prompts));

        return is_string($result) ? new EngineResult($result, [], 10, 10, 0) : $result;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }
}
