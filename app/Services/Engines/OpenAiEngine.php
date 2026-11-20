<?php

namespace App\Services\Engines;

use OpenAI\Contracts\ClientContract;

/**
 * Asks an OpenAI model through the Responses API with the web_search tool
 * enabled, so answers resemble what travellers see in ChatGPT.
 */
final class OpenAiEngine implements EngineContract
{
    public function __construct(
        private readonly ClientContract $client,
        private readonly string $model,
        private readonly string $webSearchTool = 'web_search',
        private readonly string $toolChoice = 'auto',
        // Tools and cost limits (OpenAiLimits::scan()); null = web search without limits.
        private readonly ?array $limits = null,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function ask(string $prompt, ?AskContext $context = null): EngineResult
    {
        $response = $this->client->responses()->create([
            'model' => $this->model,
            'input' => $prompt,
            'tool_choice' => $this->toolChoice,
        ] + ($this->limits ?? ['tools' => [['type' => $this->webSearchTool]]]));

        return self::fromResponseArray($response->toArray());
    }

    /**
     * Map a Responses API payload to an EngineResult.
     *
     * Text comes from output_text (or the message parts), citations from the
     * url_citation annotations on each output_text part, and web search calls
     * from the number of web_search_call output items.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponseArray(array $data): EngineResult
    {
        $texts = [];
        $citations = [];
        $webSearchCalls = 0;

        foreach ($data['output'] ?? [] as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'web_search_call') {
                $webSearchCalls++;

                continue;
            }

            if ($type !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $texts[] = (string) ($part['text'] ?? '');

                foreach ($part['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? null) !== 'url_citation' || empty($annotation['url'])) {
                        continue;
                    }

                    $citation = CitationData::fromUrl($annotation['url'], $annotation['title'] ?? null);
                    $citations[$citation->url] ??= $citation;
                }
            }
        }

        $text = isset($data['output_text']) && is_string($data['output_text']) && $data['output_text'] !== ''
            ? $data['output_text']
            : implode("\n\n", $texts);

        return new EngineResult(
            text: $text,
            citations: array_values($citations),
            tokensIn: (int) ($data['usage']['input_tokens'] ?? 0),
            tokensOut: (int) ($data['usage']['output_tokens'] ?? 0),
            webSearchCalls: $webSearchCalls,
        );
    }
}
