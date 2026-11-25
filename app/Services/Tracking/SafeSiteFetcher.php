<?php

namespace App\Services\Tracking;

use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a public web page without letting the address reach our own network (SSRF).
 *
 * Every hop (the first URL and each redirect) is checked: http or https only, no user name or password, ports
 * 80 and 443 only, and the host must resolve only to public addresses (no private, loopback, link-local,
 * carrier-grade NAT or reserved range, no "localhost"). The connection is pinned to the address that was checked,
 * so the name cannot resolve to something else a moment later (DNS rebinding). Redirects are followed by hand
 * (at most 3), the body is cut at 1.5 MB and the request times out after 8 seconds.
 */
final class SafeSiteFetcher
{
    private const MAX_BODY = 1_500_000;

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => its IP addresses (tests give fixed ones)
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? function (string $host): array {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) @gethostbynamel($host);

            foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
                $addresses[] = (string) ($record['ipv6'] ?? '');
            }

            return array_values(array_filter($addresses));
        };
    }

    /**
     * @return array{ok: bool, body: string, error: ?string}
     */
    public function fetch(string $url, int $maxRedirects = 3): array
    {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $checked = $this->check($url);

            if ($checked['error'] !== null) {
                return ['ok' => false, 'body' => '', 'error' => $checked['error']];
            }

            try {
                $options = ['allow_redirects' => false];

                if (defined('CURLOPT_RESOLVE')) {
                    $options['curl'] = [CURLOPT_RESOLVE => ["{$checked['host']}:{$checked['port']}:{$checked['ip']}"]];
                }

                $response = Http::timeout(8)->withHeaders(['User-Agent' => 'CiteVanta installation check', 'Accept' => 'text/html'])->withOptions($options)->get($url);
            } catch (Throwable) {
                return ['ok' => false, 'body' => '', 'error' => 'the website did not answer'];
            }

            if ($response->redirect() && $response->header('Location') !== '') {
                $url = self::absolute($url, $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                return ['ok' => false, 'body' => '', 'error' => 'the website answered with status '.$response->status()];
            }

            return ['ok' => true, 'body' => substr((string) $response->body(), 0, self::MAX_BODY), 'error' => null];
        }

        return ['ok' => false, 'body' => '', 'error' => 'too many redirects'];
    }

    /**
     * Whether a URL may be fetched, and the address to connect to.
     *
     * @return array{error: ?string, host: string, port: int, ip: string}
     */
    public function check(string $url): array
    {
        $fail = fn (string $why) => ['error' => $why, 'host' => '', 'port' => 0, 'ip' => ''];
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return $fail('only http and https addresses are allowed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('addresses with a user name are not allowed');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return $fail('this address is not a public website');
        }

        if (! in_array($port, [80, 443], true)) {
            return $fail('only the standard web ports are allowed');
        }

        // An address written as numbers is checked as it is, never looked up.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            return $fail('the website address could not be found');
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return $fail('this address is not a public website');
            }
        }

        return ['error' => null, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** A public internet address: not private, loopback, link-local, reserved or carrier-grade NAT. */
    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (str_contains($ip, '.')) {
            $long = ip2long($ip);

            return $long !== false && ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        $lower = strtolower($ip);

        return ! str_starts_with($lower, 'fe80') && ! str_starts_with($lower, 'fc') && ! str_starts_with($lower, 'fd') && ! str_starts_with($lower, '::ffff:');
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.ltrim($location, '/');
    }
}
