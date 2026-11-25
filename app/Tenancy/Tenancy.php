<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * The current tenant, stored on the database connection so PostgreSQL's
 * row-level security policies can enforce it on every query.
 *
 * shortcut: the setting lives on the connection, so a reconnect mid-request drops it (queries then see nothing); a long-running worker must call run() per job.
 */
#[Singleton]
class Tenancy
{
    private ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function set(Tenant|int|null $tenant): void
    {
        $this->id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        DB::select("select set_config('app.tenant_id', ?, false)", [(string) $this->id]);
    }

    /**
     * Run a callback as a tenant, then restore whichever tenant was set before, even on failure.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $previous = $this->id;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
