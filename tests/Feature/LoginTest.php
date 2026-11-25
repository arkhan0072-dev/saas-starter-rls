<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'alice@acme.test', 'password' => 'correct-horse-battery']);
});

it('returns a token for valid credentials', function () {
    $token = $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->json('token');

    $this->withToken($token)->getJson('/api/projects')->assertOk();
});

it('rejects a wrong password without saying which part was wrong', function () {
    $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
});

it('rejects an unknown email the same way', function () {
    $this->postJson('/api/login', ['email' => 'nobody@acme.test', 'password' => 'whatever'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
});

it('slows down password guessing', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => "guess-{$attempt}"])->assertUnprocessable();
    }

    $this->postJson('/api/login', ['email' => 'alice@acme.test', 'password' => 'correct-horse-battery'])
        ->assertTooManyRequests();
});

it('revokes the token on logout', function () {
    $token = $this->user->createToken('cli')->plainTextToken;

    $this->withToken($token)->postJson('/api/logout')->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/projects')->assertUnauthorized();
});
