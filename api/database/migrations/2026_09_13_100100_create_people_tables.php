<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** docs/phase-1-schema.md §3.2 — billed organisations and individual customers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 160);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 15)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('payment_terms', 20)->default('prepaid');
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->string('agent_tier', 20)->nullable();
            $table->text('travel_policy')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('phone', 15);
            $table->string('email', 190)->nullable();
            $table->string('password')->nullable();
            $table->string('stage', 20)->default('lead');
            $table->string('source', 20);
            $table->string('address', 500)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->char('locale', 2)->default('bn');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // "Unique among non-deleted rows": NULL for deleted rows, and MySQL allows many NULLs in a unique index.
            $table->string('active_phone', 15)->nullable()->storedAs('IF(`deleted_at` IS NULL, `phone`, NULL)')->unique();
            $table->string('active_email', 190)->nullable()->storedAs('IF(`deleted_at` IS NULL, `email`, NULL)')->unique();
            $table->index(['assigned_staff_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('clients');
    }
};
