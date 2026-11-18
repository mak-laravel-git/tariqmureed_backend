<?php

it('serves a json status document at the root', function () {
    $this->getJson('/')
        ->assertOk()
        ->assertJsonStructure(['name', 'status', 'api'])
        ->assertJsonPath('status', 'ok');
});

it('exposes the laravel health check', function () {
    $this->get('/up')->assertOk();
});

it('answers unknown api routes with json 404', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJson(['message' => 'Resource not found.']);
});

it('allows cross-origin requests from the configured frontend only', function () {
    $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

    $response = $this->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});
