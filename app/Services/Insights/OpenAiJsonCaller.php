<?php

namespace App\Services\Insights;

use JsonException;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

/**
 * One Responses API call with Structured Outputs (strict JSON schema) on the
 * analysis model. Used by every insight feature (accuracy, competitor reasons).
 */
final class OpenAiJsonCaller
{
    public function __construct(
        private readonly ClientContract $client,
        private readonly string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function call(string $schemaName, array $schema, string $instructions, string $input): AiJsonResponse
    {
        $response = $this->client->responses()->create([
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ]);

        $data = $response->toArray();

        try {
            $decoded = json_decode((string) ($data['output_text'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Analysis model returned invalid JSON for {$schemaName}: ".$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Analysis model returned no JSON object for {$schemaName}.");
        }

        return new AiJsonResponse(
            data: $decoded,
            tokensIn: (int) ($data['usage']['input_tokens'] ?? 0),
            tokensOut: (int) ($data['usage']['output_tokens'] ?? 0),
            model: $this->model,
        );
    }
}
