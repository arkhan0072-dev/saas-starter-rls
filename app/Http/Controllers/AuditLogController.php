<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogController extends Controller
{
    public function index(): JsonResource
    {
        return JsonResource::collection(AuditLog::orderByDesc('id')->paginate(50));
    }
}
