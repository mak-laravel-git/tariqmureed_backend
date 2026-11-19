<?php

namespace App\Services\SportMonks;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the SportMonks Cricket API v2.
 *
 * - Authenticates with the api_token query parameter (token comes from config only).
 * - Counts calls per endpoint and hour and stops before the plan limit (2000 by default).
 * - Backs off for ten minutes after an HTTP 429.
 *
 * @see https://docs.sportmonks.com/v2/cricket-api/api-rate-limit
 */
class SportMonksClient
{
    private const BLOCKED_KEY = 'sportmonks:blocked_until';

    private const LAST_RESPONSE_KEY = 'sportmonks:last_response';

    /**
     * Endpoints counted separately by SportMonks, in the order the admin panel lists them.
     *
     * @var array<int, string>
     */
    public const ENDPOINTS = ['livescores', 'fixtures', 'teams', 'players', 'leagues', 'standings', 'venues'];

    public function isConfigured(): bool
    {
        return filled(config('sportmonks.token'));
    }

    /**
     * GET an endpoint and return the decoded JSON body.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws SportMonksException
     */
    public function get(string $endpoint, array $query = []): array
    {
        $token = (string) config('sportmonks.token');

        if ($token === '') {
            throw SportMonksException::notConfigured();
        }

        $endpoint = ltrim($endpoint, '/');
        $this->guardRateLimit($endpoint);

        try {
            $response = Http::baseUrl(rtrim((string) config('sportmonks.base_url'), '/'))
                ->acceptJson()
                ->timeout((int) config('sportmonks.timeout'))
                ->retry(2, 300, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->get($endpoint, ['api_token' => $token, ...$query]);
        } catch (ConnectionException $exception) {
            // cURL errors quote the full URL, which carries the token.
            throw SportMonksException::connection(str_replace($token, '***', $exception->getMessage()));
        }

        if ($response->status() === 429) {
            Cache::put(self::BLOCKED_KEY, now()->addMinutes(10)->timestamp, now()->addMinutes(10));

            throw SportMonksException::rateLimited('SportMonks rate limit reached (HTTP 429). Pausing requests for 10 minutes.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw SportMonksException::unauthorized($response->status());
        }

        if (! $response->successful()) {
            throw SportMonksException::requestFailed($endpoint, $response->status());
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        Cache::put(self::LAST_RESPONSE_KEY, [
            'at' => now()->toIso8601String(),
            'meta' => $body['meta'] ?? null,
        ], now()->addDay());

        return $body;
    }

    /**
     * Walk every page of a paginated endpoint (page=1, 2, ...) and yield each row.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     *
     * @throws SportMonksException
     */
    public function paginate(string $endpoint, array $query = [], int $maxPages = 60): Generator
    {
        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->get($endpoint, [...$query, 'page' => $page]);
            $rows = is_array($body['data'] ?? null) ? $body['data'] : [];

            foreach ($rows as $row) {
                yield $row;
            }

            // The live API reports meta.last_page; the docs mention meta.pagination.total_pages.
            $totalPages = (int) ($body['meta']['last_page'] ?? $body['meta']['pagination']['total_pages'] ?? 0);

            if ($rows === [] || $totalPages === 0 || $page >= $totalPages) {
                break;
            }
        }
    }

    /**
     * Calls made in the current hour per endpoint, plus the plan limit and the last response meta.
     *
     * @return array{limit: int, endpoints: array<string, int>, blocked_until: ?string, last_response: ?array<string, mixed>}
     */
    public function usage(): array
    {
        $blockedUntil = (int) Cache::get(self::BLOCKED_KEY, 0);

        return [
            'limit' => (int) config('sportmonks.rate_limit.per_endpoint_hour'),
            'endpoints' => collect(self::ENDPOINTS)
                ->mapWithKeys(fn (string $endpoint): array => [$endpoint => (int) Cache::get($this->callsKey($endpoint), 0)])
                ->all(),
            'blocked_until' => $blockedUntil > time() ? date(DATE_ATOM, $blockedUntil) : null,
            'last_response' => Cache::get(self::LAST_RESPONSE_KEY),
        ];
    }

    /**
     * @throws SportMonksException
     */
    private function guardRateLimit(string $endpoint): void
    {
        if ((int) Cache::get(self::BLOCKED_KEY, 0) > time()) {
            throw SportMonksException::rateLimited('SportMonks requests are paused after a rate limit response.');
        }

        $key = $this->callsKey($endpoint);
        Cache::add($key, 0, now()->addHours(2));

        $budget = (int) config('sportmonks.rate_limit.per_endpoint_hour') - (int) config('sportmonks.rate_limit.reserve');

        if ((int) Cache::increment($key) > $budget) {
            throw SportMonksException::rateLimited("The hourly call budget for '{$this->segment($endpoint)}' is used up. It resets at the start of the next hour.");
        }
    }

    private function callsKey(string $endpoint): string
    {
        return 'sportmonks:calls:'.$this->segment($endpoint).':'.now()->format('YmdH');
    }

    private function segment(string $endpoint): string
    {
        return explode('/', ltrim($endpoint, '/'))[0];
    }
}
