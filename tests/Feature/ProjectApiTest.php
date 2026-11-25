<?php

use App\Enums\Role;
use App\Http\Middleware\SetTenant;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->acme = Tenant::factory()->create();
    $this->globex = Tenant::factory()->create();
    $this->alice = User::factory()->for($this->acme)->create();

    app(Tenancy::class)->run($this->acme, fn () => Project::factory()->create(['name' => 'Acme launch']));
    app(Tenancy::class)->run($this->globex, fn () => Project::factory()->create(['name' => 'Globex secret']));
});

it('lists only the signed-in user\'s tenant projects', function () {
    $this->actingAs($this->alice, 'sanctum')
        ->getJson('/api/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Acme launch');
});

it('creates a project in the user\'s tenant', function () {
    $this->actingAs($this->alice, 'sanctum')
        ->postJson('/api/projects', ['name' => 'Q3 plan'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Q3 plan')
        ->assertJsonPath('data.tenant_id', $this->acme->id);
});

it('ignores a tenant_id sent by the client', function () {
    $this->actingAs($this->alice, 'sanctum')
        ->postJson('/api/projects', ['name' => 'Sneaky', 'tenant_id' => $this->globex->id])
        ->assertCreated()
        ->assertJsonPath('data.tenant_id', $this->acme->id);
});

it('validates the project name', function () {
    $this->actingAs($this->alice, 'sanctum')
        ->postJson('/api/projects', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('requires authentication', function () {
    $this->getJson('/api/projects')->assertUnauthorized();
});

it('refuses the request if the tenant middleware runs without auth', function () {
    Route::middleware(SetTenant::class)->get('/misconfigured', fn () => 'leaked');

    $this->getJson('/misconfigured')->assertUnauthorized();
});

it('clears the tenant after the request', function () {
    $this->actingAs($this->alice, 'sanctum')->getJson('/api/projects')->assertOk();

    expect(app(Tenancy::class)->id())->toBeNull();
});

it('lets an admin delete a project', function () {
    $admin = User::factory()->for($this->acme)->create(['role' => Role::Admin]);
    $project = app(Tenancy::class)->run($this->acme, fn () => Project::sole());

    $this->actingAs($admin, 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertNoContent();

    expect(app(Tenancy::class)->run($this->acme, fn () => Project::count()))->toBe(0);
});

it('does not let a member delete a project', function () {
    $project = app(Tenancy::class)->run($this->acme, fn () => Project::sole());

    $this->actingAs($this->alice, 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertForbidden();
});

it('answers 404 for another tenant\'s project, as if it did not exist', function () {
    $admin = User::factory()->for($this->acme)->create(['role' => Role::Admin]);
    $secret = app(Tenancy::class)->run($this->globex, fn () => Project::sole());

    $this->actingAs($admin, 'sanctum')->deleteJson("/api/projects/{$secret->id}")->assertNotFound();

    expect(app(Tenancy::class)->run($this->globex, fn () => Project::count()))->toBe(1);
});
