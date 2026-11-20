<?php

namespace App\Services\Engines;

/**
 * An AI assistant that answers a traveller's question the way end users
 * would see it (e.g. ChatGPT with web search).
 */
interface EngineContract
{
    public function ask(string $prompt, ?AskContext $context = null): EngineResult;

    /** Short engine identifier stored on scan_runs.engine, e.g. "openai". */
    public function name(): string;

    /** Model identifier stored on scan_runs.model. */
    public function model(): string;
}
