<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->acme)->create(['role' => Role::Admin]);
});

function auditTrail(Tenant $tenant): array
{
    return app(Tenancy::class)->run($tenant, fn () => AuditLog::orderBy('id')->get()->toArray());
}

it('records who created a project and with what values', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/projects', ['name' => 'Roadmap'])->assertCreated();

    $entry = auditTrail($this->acme)[0];

    expect($entry)->toMatchArray([
        'action' => 'project.created',
        'user_id' => $this->admin->id,
        'subject_type' => 'project',
        'changes' => ['name' => 'Roadmap'],
    ]);
});

it('records only the fields that changed, with old and new values', function () {
    $project = $this->tenancy->run($this->acme, fn () => Project::create(['name' => 'Draft']));

    $this->tenancy->run($this->acme, fn () => $project->update(['name' => 'Final']));

    expect(auditTrail($this->acme)[1])->toMatchArray([
        'action' => 'project.updated',
        'subject_id' => $project->id,
        'changes' => ['name' => ['old' => 'Draft', 'new' => 'Final']],
    ]);
});

it('skips saves that change nothing', function () {
    $project = $this->tenancy->run($this->acme, fn () => Project::create(['name' => 'Draft']));

    $this->tenancy->run($this->acme, fn () => $project->touch());

    expect(auditTrail($this->acme))->toHaveCount(1);
});

it('records deletions', function () {
    $project = $this->tenancy->run($this->acme, fn () => Project::create(['name' => 'Old idea']));

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertNoContent();

    expect(auditTrail($this->acme)[1])->toMatchArray([
        'action' => 'project.deleted',
        'user_id' => $this->admin->id,
        'changes' => ['name' => 'Old idea'],
    ]);
});

it('never copies hidden fields such as token hashes into the log', function () {
    Notification::fake();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/invitations', ['email' => 'bob@example.test', 'role' => 'member'])
        ->assertCreated();

    $entry = auditTrail($this->acme)[0];
    expect($entry['action'])->toBe('invitation.created')
        ->and($entry['changes'])->toHaveKey('email')
        ->and($entry['changes'])->not->toHaveKey('token_hash');
});

it('cannot be changed or deleted by the application', function (string $sql) {
    $this->tenancy->run($this->acme, function () use ($sql) {
        Project::create(['name' => 'Evidence']);

        DB::statement($sql);
    });
})->with([
    'update' => "update audit_logs set action = 'nothing.happened'",
    'delete' => 'delete from audit_logs',
])->throws(QueryException::class, 'permission denied');

it('keeps each tenant\'s log private', function () {
    $this->tenancy->run($this->acme, fn () => Project::create(['name' => 'Acme plan']));
    $globex = Tenant::factory()->create();

    expect(auditTrail($globex))->toBe([])
        ->and(DB::table('audit_logs')->count())->toBe(0);
});

it('shows admins the newest entries first', function () {
    $this->tenancy->run($this->acme, function () {
        Project::create(['name' => 'First']);
        Project::create(['name' => 'Second']);
    });

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/audit-log')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.changes.name', 'Second');
});

it('hides the log from members', function () {
    $member = User::factory()->for($this->acme)->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/audit-log')->assertForbidden();
});
