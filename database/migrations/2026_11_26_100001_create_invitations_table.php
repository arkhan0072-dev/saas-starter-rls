<?php

use App\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        RowLevelSecurity::enable('invitations');

        // Someone accepting an invitation is not signed in, so no tenant is set and RLS
        // hides every invitation. This function answers one narrow question with the
        // owner's rights: which tenant does this unused token belong to? It needs the
        // owner role to bypass RLS (superuser or BYPASSRLS).
        DB::unprepared(<<<'SQL'
            create or replace function invitation_tenant(p_token_hash text) returns bigint
            language sql stable security definer set search_path = public
            as $$ select tenant_id from invitations where token_hash = p_token_hash and accepted_at is null $$;

            revoke execute on function invitation_tenant(text) from public;
        SQL);
        DB::statement('grant execute on function invitation_tenant(text) to '.RowLevelSecurity::appRole());
    }

    public function down(): void
    {
        DB::statement('drop function if exists invitation_tenant(text)');
        Schema::dropIfExists('invitations');
    }
};
