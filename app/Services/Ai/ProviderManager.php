<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Services\SimulatedMonitoring;
use InvalidArgumentException;

class ProviderManager
{
    public function resolve(AiProvider $provider): AiProviderAdapter
    {
        if ($provider->enabled && $provider->identifier === 'simulated') {
            app(SimulatedMonitoring::class)->assertAvailable();

            return app(SimulatedProvider::class);
        }
        if (! $provider->enabled || $provider->identifier !== 'openai') {
            throw new InvalidArgumentException('This provider is unavailable.');
        }

        return app(OpenAiProvider::class);
    }
}
