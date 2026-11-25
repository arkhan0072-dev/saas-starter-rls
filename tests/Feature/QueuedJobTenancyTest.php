<?php

use App\Enums\Role;
use App\Jobs\ExportProjects;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Jobs go through the real database queue and a worker, where no request and no
// tenant exist, to prove the tenant travels with the job.

beforeEach(function () {
    Storage::fake();
    config(['queue.default' => 'database']);

    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create();
    $this->globex = Tenant::factory()->create();
    $this->tenancy->run($this->acme, fn () => Project::factory()->create(['name' => 'Acme roadmap']));
    $this->tenancy->run($this->globex, fn () => Project::factory()->create(['name' => 'Globex secret']));
});

function work(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
}

it('runs a queued job inside the tenant that dispatched it', function () {
    // A statement body, not fn () => ...: dispatch() queues the job when its PendingDispatch
    // is destroyed, which with an arrow function would happen after run() cleared the tenant.
    $this->tenancy->run($this->acme, function () {
        ExportProjects::dispatch();
    });
    expect($this->tenancy->id())->toBeNull();

    work();

    expect(Storage::get("exports/projects-{$this->acme->id}.csv"))->toBe("name\n\"Acme roadmap\"\n");
});

it('clears the tenant once the job is done', function () {
    $this->tenancy->run($this->acme, function () {
        ExportProjects::dispatch();
    });

    work();

    expect($this->tenancy->id())->toBeNull()
        ->and(DB::scalar('select count(*) from projects'))->toBe(0);
});

it('refuses to run a tenant job that was dispatched without a tenant', function () {
    ExportProjects::dispatch();

    work();

    expect(Storage::allFiles('exports'))->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('lets an admin start an export', function () {
    $admin = User::factory()->for($this->acme)->create(['role' => Role::Admin]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/projects/export')->assertAccepted();
    work();

    expect(Storage::exists("exports/projects-{$this->acme->id}.csv"))->toBeTrue();
});

it('does not let a member start an export', function () {
    $member = User::factory()->for($this->acme)->create();

    $this->actingAs($member, 'sanctum')->postJson('/api/projects/export')->assertForbidden();
});
