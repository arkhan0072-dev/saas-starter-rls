<?php

use App\Enums\Role;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

it('sends guests to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('does not let a customer into the admin panel, even when signed in', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);

    $this->actingAs($owner)->get('/admin')->assertRedirect('/admin/login');
});

it('lets platform staff in', function () {
    $this->actingAs($this->admin, 'admin')->get('/admin')->assertOk();
});

it('lists tenants with their plan and subscription status', function () {
    $acme = Tenant::factory()->create(['name' => 'Acme']);
    $acme->forceFill(['plan' => 'pro', 'subscription_status' => 'past_due'])->save();
    User::factory()->count(2)->for($acme)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListTenants::class)
        ->assertCanSeeTableRecords([$acme])
        ->assertSee(['Acme', 'pro', 'past_due'])
        ->assertTableColumnStateSet('users_count', 2, $acme);
});

it('lists users with their tenant and role', function () {
    $user = User::factory()->for(Tenant::factory()->create(['name' => 'Globex']))->create(['role' => Role::Admin]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertSee(['Globex', 'admin']);
});

it('is read-only', function (string $route) {
    expect(Route::has($route))->toBeFalse();
})->with([
    'filament.admin.resources.tenants.create',
    'filament.admin.resources.tenants.edit',
    'filament.admin.resources.users.create',
    'filament.admin.resources.users.edit',
]);

it('has no screens for customer data', function () {
    expect(Route::has('filament.admin.resources.projects.index'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.audit-logs.index'))->toBeFalse();
});
