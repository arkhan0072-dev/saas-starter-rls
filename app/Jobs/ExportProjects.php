<?php

namespace App\Jobs;

use App\Models\Project;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Writes the tenant's projects to a CSV file. An example of a tenant-aware queued job. */
class ExportProjects implements ShouldQueue
{
    use Queueable, TenantAware;

    public function handle(): void
    {
        $tenantId = $this->tenantId();

        $csv = "name\n";
        foreach (Project::orderBy('id')->get() as $project) {
            $csv .= '"'.str_replace('"', '""', $project->name)."\"\n";
        }

        Storage::put("exports/projects-{$tenantId}.csv", $csv);
    }
}
