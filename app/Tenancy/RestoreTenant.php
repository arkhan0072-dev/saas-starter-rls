<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Queue\Job;

/**
 * Job middleware: runs the job inside the tenant that was current when it was dispatched.
 * The tenant id is added to every queue payload in AppServiceProvider.
 */
class RestoreTenant
{
    public function handle(object $job, Closure $next): mixed
    {
        $queued = property_exists($job, 'job') ? $job->job : null;
        $tenantId = $queued instanceof Job ? ($queued->payload()['tenant_id'] ?? null) : null;

        if (! is_int($tenantId)) {
            return $next($job);
        }

        return app(Tenancy::class)->run($tenantId, fn () => $next($job));
    }
}
