<?php

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->acme = Tenant::factory()->create(['name' => 'Acme']);
    $this->owner = User::factory()->for($this->acme)->create(['role' => Role::Owner]);
});

/** Invite someone as $inviter and return the token from the email that was sent. */
function invite(User $inviter, string $email = 'bob@example.test', string $role = 'member'): string
{
    test()->actingAs($inviter, 'sanctum')
        ->postJson('/api/invitations', ['email' => $email, 'role' => $role])
        ->assertSuccessful();

    $token = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $mail, array $channels, object $notifiable) use ($email, &$token) {
        $token = $mail->token;

        return $notifiable->routes['mail'] === $email;
    });

    return $token;
}

function accept(string $token, array $overrides = []): TestResponse
{
    return test()->postJson('/api/invitations/accept', [
        'token' => $token,
        'name' => 'Bob',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        ...$overrides,
    ]);
}

it('emails an invitation and stores only a hash of the token', function () {
    $token = invite($this->owner);

    $invitation = app(Tenancy::class)->run($this->acme, fn () => Invitation::sole());

    expect($invitation->email)->toBe('bob@example.test')
        ->and($invitation->role)->toBe(Role::Member)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
});

it('names the company in the invitation email', function () {
    $mail = (new TeamInvitation('secret-token', 'Acme'))->toMail(new stdClass);

    expect($mail->subject)->toBe('You have been invited to join Acme')
        ->and(implode(' ', $mail->introLines))->toContain('secret-token');
});

it('lets an admin invite teammates', function () {
    $admin = User::factory()->for($this->acme)->create(['role' => Role::Admin]);

    expect(invite($admin, role: 'admin'))->toBeString();
});

it('does not let a member invite anyone', function () {
    $member = User::factory()->for($this->acme)->create(['role' => Role::Member]);

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/invitations', ['email' => 'bob@example.test', 'role' => 'member'])
        ->assertForbidden();
});

it('does not hand out the owner role by invitation', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/invitations', ['email' => 'bob@example.test', 'role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

it('does not invite an email that already has an account', function () {
    User::factory()->create(['email' => 'bob@example.test']);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/invitations', ['email' => 'bob@example.test', 'role' => 'member'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('replaces a pending invitation when the same email is invited again', function () {
    $first = invite($this->owner);
    Notification::fake();
    $second = invite($this->owner, role: 'admin');

    accept($first)->assertUnprocessable();
    accept($second)->assertCreated()->assertJsonPath('user.role', 'admin');
});

it('creates the account in the inviting tenant with the invited role', function () {
    $token = invite($this->owner, role: 'admin');

    $response = accept($token)->assertCreated();

    $bob = User::where('email', 'bob@example.test')->sole();
    expect($bob->tenant_id)->toBe($this->acme->id)
        ->and($bob->role)->toBe(Role::Admin)
        ->and($response->json('token'))->toBeString();

    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('token'))->getJson('/api/projects')->assertOk();
});

it('accepts an invitation only once', function () {
    $token = invite($this->owner);

    accept($token)->assertCreated();
    accept($token, ['name' => 'Mallory'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token' => 'This invitation is invalid or has expired.']);
});

it('rejects an expired invitation', function () {
    $token = invite($this->owner);

    $this->travel(8)->days();

    accept($token)->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('rejects a made-up token', function () {
    accept('not-a-real-token')->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('validates the new account details', function () {
    $token = invite($this->owner);

    accept($token, ['password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('refuses an invitation whose email registered in the meantime', function () {
    $token = invite($this->owner);
    User::factory()->create(['email' => 'bob@example.test']);

    accept($token)->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('keeps invitations inside their tenant', function () {
    invite($this->owner);
    $globex = Tenant::factory()->create();

    expect(DB::table('invitations')->count())->toBe(0)
        ->and(app(Tenancy::class)->run($globex, fn () => Invitation::count()))->toBe(0)
        ->and(app(Tenancy::class)->run($this->acme, fn () => Invitation::count()))->toBe(1);
});
