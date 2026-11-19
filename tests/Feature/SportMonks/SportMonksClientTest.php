<?php

use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['sportmonks.token' => 'secret-token-123']);
});

it('refuses to call SportMonks without a token', function () {
    config(['sportmonks.token' => null]);
    Http::fake();

    expect(fn () => app(SportMonksClient::class)->get('leagues'))
        ->toThrow(SportMonksException::class, 'SPORTMONKS_API_TOKEN');

    Http::assertNothingSent();
});

it('sends the token and query parameters to the cricket api', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 1]]])]);

    $body = app(SportMonksClient::class)->get('leagues', ['include' => 'seasons']);

    expect($body['data'])->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://cricket.sportmonks.com/api/v2.0/leagues')
        && $request['api_token'] === 'secret-token-123'
        && $request['include'] === 'seasons');
});

it('pauses all requests after a 429 response', function () {
    Http::fake(['*' => Http::response([], 429)]);
    $client = app(SportMonksClient::class);

    expect(fn () => $client->get('fixtures'))->toThrow(SportMonksException::class, 'rate limit');
    expect(fn () => $client->get('teams'))->toThrow(SportMonksException::class, 'paused');

    Http::assertSentCount(1);
    expect($client->usage()['blocked_until'])->not->toBeNull();
});

it('reports a rejected token without leaking it', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);

    try {
        app(SportMonksClient::class)->get('leagues');
    } catch (SportMonksException $exception) {
        expect($exception->reason)->toBe(SportMonksException::UNAUTHORIZED)
            ->and($exception->isFatal())->toBeTrue()
            ->and($exception->getMessage())->not->toContain('secret-token-123');

        return;
    }

    $this->fail('Expected a SportMonksException.');
});

it('hides the token when the connection fails', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6 for https://cricket.sportmonks.com/api/v2.0/leagues?api_token=secret-token-123'));

    try {
        app(SportMonksClient::class)->get('leagues');
    } catch (SportMonksException $exception) {
        expect($exception->getMessage())->toContain('Could not reach SportMonks')
            ->not->toContain('secret-token-123');

        return;
    }

    $this->fail('Expected a SportMonksException.');
});

it('stops before the hourly budget of an endpoint is spent', function () {
    config(['sportmonks.rate_limit.per_endpoint_hour' => 4, 'sportmonks.rate_limit.reserve' => 1]);
    Http::fake(['*' => Http::response(['data' => []])]);
    $client = app(SportMonksClient::class);

    $client->get('fixtures');
    $client->get('fixtures/1');
    $client->get('fixtures/2');
    $client->get('teams');

    expect(fn () => $client->get('fixtures/3'))->toThrow(SportMonksException::class, "budget for 'fixtures'");

    Http::assertSentCount(4);
    expect($client->usage()['endpoints']['fixtures'])->toBeGreaterThanOrEqual(3);
});

it('walks every page using the live api meta (last_page)', function () {
    Http::fake([
        '*page=2*' => Http::response(['data' => [['id' => 3]], 'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 3]]),
        '*' => Http::response(['data' => [['id' => 1], ['id' => 2]], 'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3]]),
    ]);

    $ids = collect(app(SportMonksClient::class)->paginate('fixtures'))->pluck('id')->all();

    expect($ids)->toBe([1, 2, 3]);
    Http::assertSentCount(2);
});

it('also understands the documented pagination meta', function () {
    Http::fake([
        '*page=2*' => Http::response(['data' => [['id' => 3]], 'meta' => ['pagination' => ['total_pages' => 2]]]),
        '*' => Http::response(['data' => [['id' => 1], ['id' => 2]], 'meta' => ['pagination' => ['total_pages' => 2]]]),
    ]);

    expect(collect(app(SportMonksClient::class)->paginate('fixtures'))->pluck('id')->all())->toBe([1, 2, 3]);
});
