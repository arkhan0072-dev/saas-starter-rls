<?php

namespace App\Tenancy;

/** For queued jobs that work on tenant data. */
trait TenantAware
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [new RestoreTenant];
    }

    /** Jobs that need a tenant refuse to run without one, rather than silently seeing no rows. */
    protected function tenantId(): int
    {
        return app(Tenancy::class)->id() ?? throw new MissingTenant(static::class.' was dispatched outside a tenant.');
    }
}
