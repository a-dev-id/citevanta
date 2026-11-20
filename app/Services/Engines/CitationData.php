<?php

namespace App\Services\Engines;

final readonly class CitationData
{
    public function __construct(
        public string $url,
        public string $domain,
        public ?string $title = null,
    ) {}

    /**
     * Build from a raw URL: strips the "utm_source=openai|chatgpt.com" tracking
     * parameter OpenAI appends, and derives the domain without a leading "www.".
     */
    public static function fromUrl(string $url, ?string $title = null): self
    {
        $url = self::stripOpenAiTracking(trim($url));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = preg_replace('/^www\./', '', $host) ?: $host;

        $title = $title !== null ? trim($title) : null;

        return new self($url, $domain, $title === '' ? null : $title);
    }

    private static function stripOpenAiTracking(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        parse_str($query, $params);

        if (! in_array($params['utm_source'] ?? null, ['openai', 'chatgpt.com'], true)) {
            return $url;
        }

        unset($params['utm_source']);

        $base = strtok($url, '?');
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $rebuilt = $params === [] ? $base : $base.'?'.http_build_query($params);

        return is_string($fragment) ? $rebuilt.'#'.$fragment : $rebuilt;
    }
}
