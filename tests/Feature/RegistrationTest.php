<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Testing\TestResponse;

function signUp(array $overrides = []): TestResponse
{
    return test()->postJson('/api/register', [
        'company' => 'Initech',
        'name' => 'Peter Gibbons',
        'email' => 'peter@initech.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        ...$overrides,
    ]);
}

it('creates a tenant with its first user and returns a token', function () {
    $response = signUp()->assertCreated();

    $user = User::where('email', 'peter@initech.test')->sole();

    expect($user->tenant->name)->toBe('Initech')
        ->and($user->role)->toBe(Role::Owner)
        ->and($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('user'))->toMatchArray(['name' => 'Peter Gibbons', 'email' => 'peter@initech.test', 'tenant_id' => $user->tenant_id])
        ->and($response->json('user'))->not->toHaveKey('password');
});

it('gives the new tenant an empty workspace, not someone else\'s', function () {
    $other = Tenant::factory()->create();
    app(Tenancy::class)->run($other, fn () => Project::factory()->create());

    $token = signUp()->json('token');

    $this->withToken($token)->getJson('/api/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects an email that is already registered', function () {
    User::factory()->create(['email' => 'peter@initech.test']);

    signUp()->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('validates the sign-up form', function (array $input, string $field) {
    signUp($input)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing company' => [['company' => ''], 'company'],
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'something-else'], 'password'],
]);

it('creates nothing if the user cannot be saved', function () {
    User::creating(fn () => throw new RuntimeException('disk full'));

    signUp()->assertServerError();

    expect(Tenant::count())->toBe(0);
});
