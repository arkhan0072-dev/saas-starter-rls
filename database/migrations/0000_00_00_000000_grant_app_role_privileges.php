<?php

use App\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the runtime role read and write every table these migrations create,
 * without owning them (owners could disable row-level security).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = RowLevelSecurity::appRole();

        DB::statement("grant usage on schema public to {$role}");
        DB::statement("alter default privileges in schema public grant select, insert, update, delete on tables to {$role}");
        DB::statement("alter default privileges in schema public grant usage, select on sequences to {$role}");
    }

    public function down(): void
    {
        $role = RowLevelSecurity::appRole();

        DB::statement("alter default privileges in schema public revoke select, insert, update, delete on tables from {$role}");
        DB::statement("alter default privileges in schema public revoke usage, select on sequences from {$role}");
    }
};
