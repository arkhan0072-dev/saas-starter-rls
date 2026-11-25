<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('plan', 16)->default('free');
            $table->string('subscription_status', 32)->nullable();
            $table->timestamp('current_period_end')->nullable();
            // Creation time of the last Stripe event applied; older events arriving late are ignored.
            $table->timestamp('subscription_synced_at')->nullable();
        });

        // Event ids already processed, so a webhook Stripe delivers twice is applied once.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'plan', 'subscription_status', 'current_period_end', 'subscription_synced_at']);
        });
    }
};
