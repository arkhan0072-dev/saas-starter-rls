<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/** Used by migrations to isolate a table by its tenant_id column. */
class RowLevelSecurity
{
    /**
     * Rows are visible and writable only when tenant_id matches the connection's
     * app.tenant_id setting. With no tenant set the setting is empty or missing,
     * the comparison is NULL, and every row is hidden: the table fails closed.
     */
    public static function enable(string $table): void
    {
        $table = DB::getQueryGrammar()->wrapTable($table);
        $current = "nullif(current_setting('app.tenant_id', true), '')::bigint";

        DB::statement("alter table {$table} enable row level security");
        // Without FORCE, the table owner would bypass the policy.
        DB::statement("alter table {$table} force row level security");
        DB::statement("create policy tenant_isolation on {$table} using (tenant_id = {$current}) with check (tenant_id = {$current})");
    }
}
