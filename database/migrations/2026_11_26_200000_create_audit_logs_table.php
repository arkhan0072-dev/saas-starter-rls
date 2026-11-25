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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('changes');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'id']);
        });

        RowLevelSecurity::enable('audit_logs');

        // Append-only for the application: history cannot be rewritten, even by a bug.
        DB::statement('revoke update, delete, truncate on audit_logs from '.RowLevelSecurity::appRole());
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
