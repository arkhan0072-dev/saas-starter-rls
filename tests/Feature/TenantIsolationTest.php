<?php

use App\Models\Project;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// These tests query with raw SQL on purpose: isolation must hold even when
// application code forgets a "where tenant_id = ?" clause.

beforeEach(function () {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create(['name' => 'Acme']);
    $this->globex = Tenant::factory()->create(['name' => 'Globex']);

    $this->tenancy->run($this->acme, fn () => Project::factory()->count(2)->create());
    $this->tenancy->run($this->globex, fn () => Project::factory()->create(['name' => 'Globex secret']));
});

it('connects as a role that cannot bypass row-level security', function () {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

it('shows a tenant only its own rows, even to raw SQL', function () {
    $this->tenancy->set($this->acme);

    expect(DB::scalar('select count(*) from projects'))->toBe(2)
        ->and(Project::pluck('name'))->not->toContain('Globex secret');
});

it('shows nothing when no tenant is set', function () {
    expect(DB::scalar('select count(*) from projects'))->toBe(0);
});

it('refuses to insert a row for another tenant', function () {
    $this->tenancy->set($this->acme);

    DB::table('projects')->insert(['tenant_id' => $this->globex->id, 'name' => 'Planted']);
})->throws(QueryException::class, 'row-level security policy');

it('refuses to insert when no tenant is set', function () {
    DB::table('projects')->insert(['tenant_id' => $this->acme->id, 'name' => 'Orphan']);
})->throws(QueryException::class, 'row-level security policy');

it('cannot update or delete another tenant\'s rows', function () {
    $this->tenancy->set($this->acme);

    expect(DB::table('projects')->update(['name' => 'Hacked']))->toBe(2)
        ->and(DB::table('projects')->delete())->toBe(2);

    $this->tenancy->run($this->globex, function () {
        expect(Project::pluck('name')->all())->toBe(['Globex secret']);
    });
});

it('cannot move a row to another tenant', function () {
    $this->tenancy->set($this->acme);

    DB::table('projects')->update(['tenant_id' => $this->globex->id]);
})->throws(QueryException::class, 'row-level security policy');

it('fills in the current tenant on new models', function () {
    $project = $this->tenancy->run($this->acme, fn () => Project::create(['name' => 'Roadmap']));

    expect($project->tenant_id)->toBe($this->acme->id);
});

it('restores the previous tenant after run()', function () {
    $this->tenancy->set($this->acme);

    $this->tenancy->run($this->globex, fn () => null);

    expect($this->tenancy->id())->toBe($this->acme->id);
});

it('restores the previous tenant when run() throws', function () {
    try {
        $this->tenancy->run($this->globex, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($this->tenancy->id())->toBeNull()
        ->and(DB::scalar('select count(*) from projects'))->toBe(0);
});
