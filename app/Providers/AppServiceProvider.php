<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every queued job carries the tenant it was dispatched in; RestoreTenant switches back to it.
        Queue::createPayloadUsing(fn () => ['tenant_id' => app(Tenancy::class)->id()]);

        Gate::define('manage-tenant', fn (User $user) => $user->role->canManage());
        Gate::define('manage-billing', fn (User $user) => $user->role === Role::Owner);

        // Five login attempts a minute per email and IP address.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            mb_strtolower($request->string('email')->value()).'|'.$request->ip()
        ));
    }
}
