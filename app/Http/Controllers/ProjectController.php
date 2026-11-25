<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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

        return new JsonResource(Project::create(['name' => $request->string('name')->value()]));
    }
}
