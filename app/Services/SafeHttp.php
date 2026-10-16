<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches URLs that people paste into the app without letting them reach the
 * server's own network: every hop (including redirects) must be a plain
 * http(s) URL on a standard port that resolves to a public address, and the
 * connection is pinned to the address that was checked.
 */
class SafeHttp
{
    private const MAX_REDIRECTS = 5;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; HomePantry/1.0; +https://github.com/daezerwin/home-inventory)';

    /**
     * @param  list<string>  $allowedHosts  empty = any public host
     * @return array{0: Response, 1: string} the final response and the URL it came from
     */
    public function get(string $url, int $maxBytes, ?string $sink = null, array $allowedHosts = [], array $headers = []): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->resolve($url, $allowedHosts);

            $options = [
                // Redirects are followed by this loop so each hop gets checked.
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                'progress' => static function ($size, $downloaded) use ($maxBytes) {
                    if ($size > $maxBytes || $downloaded > $maxBytes) {
                        throw new RuntimeException('The download is larger than '.round($maxBytes / 1048576).' MB.');
                    }
                },
            ];

            if ($sink) {
                $options['sink'] = $sink;
            }

            $response = Http::connectTimeout(5)
                ->timeout(25)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders($headers)
                ->withOptions($options)
                ->get($url);

            if (! $response->redirect()) {
                return [$response, $url];
            }

            $location = $response->header('Location');

            if (! $location) {
                throw new RuntimeException('The site redirected without saying where to.');
            }

            $url = $this->absolute($location, $url);
        }

        throw new RuntimeException('The site redirected too many times.');
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the public IP it resolves to
     */
    public function resolve(string $url, array $allowedHosts = []): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Only plain http(s) web addresses can be fetched.');
        }

        if ($allowedHosts && ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException("Host {$host} is not in INVENTORY_IMAGE_HOSTS.");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Only the standard web ports can be used.');
        }

        $ip = gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException("{$host} does not resolve to a public address.");
        }

        return [$host, $port, $ip];
    }

    /** Resolves a (possibly relative) URL found on a page against that page's URL. */
    public function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.$directory.$location;
    }
}
