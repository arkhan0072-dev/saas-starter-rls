<?php

namespace App\Http\Controllers;

use App\Jobs\ExportProjects;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    // No "where tenant_id = ?" anywhere: row-level security scopes every query.
    public function index(): JsonResource
    {
        return JsonResource::collection(Project::orderBy('id')->get());
    }

    public function store(Request $request): JsonResource
    {
        $request->validate(['name' => ['required', 'string', 'max:255']]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // shortcut: two simultaneous requests can both pass this check, allowing one extra project; lock the tenant row if the limit must be exact.
        if (! $user->tenant->hasActiveSubscription() && Project::count() >= Tenant::FREE_PROJECT_LIMIT) {
            abort(403, 'The free plan includes up to '.Tenant::FREE_PROJECT_LIMIT.' projects. Upgrade to add more.');
        }

        return new JsonResource(Project::create(['name' => $request->string('name')->value()]));
    }

    public function export(): JsonResponse
    {
        ExportProjects::dispatch();

        return response()->json(['message' => 'Export started.'], 202);
    }

    public function destroy(Project $project): Response
    {
        $project->delete();

        return response()->noContent();
    }
}
