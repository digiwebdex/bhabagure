<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** docs/phase-1-schema.md §3.1 — admin users, authenticatable on the `staff` guard. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->unique();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('phone', 15)->nullable();
            $table->string('password');
            $table->string('status', 20)->default('invited');
            $table->boolean('must_change_password')->default(true);
            $table->char('locale', 2)->default('bn');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Opaque refresh tokens (the access token is a 15-minute JWT). Stored hashed and rotated on every
        // use; presenting an already-rotated token revokes its whole family, which ends a stolen session.
        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('guard', 20);
            $table->unsignedBigInteger('subject_id');
            $table->char('token_hash', 64)->unique();
            $table->uuid('family');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guard', 'subject_id']);
            $table->index('family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_refresh_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('staff');
    }
};
