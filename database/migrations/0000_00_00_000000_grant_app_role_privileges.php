<?php

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
        $role = $this->appRole();

        DB::statement("grant usage on schema public to {$role}");
        DB::statement("alter default privileges in schema public grant select, insert, update, delete on tables to {$role}");
        DB::statement("alter default privileges in schema public grant usage, select on sequences to {$role}");
    }

    public function down(): void
    {
        $role = $this->appRole();

        DB::statement("alter default privileges in schema public revoke select, insert, update, delete on tables from {$role}");
        DB::statement("alter default privileges in schema public revoke usage, select on sequences from {$role}");
    }

    private function appRole(): string
    {
        return DB::connection('pgsql')->getQueryGrammar()->wrap(config()->string('database.connections.pgsql.username'));
    }
};
