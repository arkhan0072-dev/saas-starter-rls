<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// tenant_id is deliberately not fillable: it always comes from the current tenant.
#[Fillable(['name'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Audited, BelongsToTenant, HasFactory;
}
