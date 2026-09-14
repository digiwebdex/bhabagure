<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §4: HR records, invitation and password-reset links, and staff documents.
 * Staff are never deleted — punches, payslips and ledgers point at them — so the staff foreign keys here restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff');
            $table->string('designation', 80)->nullable();
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->date('date_of_birth')->nullable();
            // Encrypted with APP_KEY like passport numbers, with an HMAC to spot the same NID on two records.
            $table->text('nid_number')->nullable();
            $table->char('nid_number_hash', 64)->nullable()->index();
            $table->string('address', 300)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            // Where salary goes: bank · bkash · nagad · rocket · cash, and the account or number (encrypted).
            $table->string('payout_method', 20)->nullable();
            $table->text('payout_account')->nullable();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        // A link that sets a password: `invite` for a new account, `reset` for a forgotten one. Only the hash is stored.
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->string('purpose', 10);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'used_at', 'cancelled_at']);
        });

        Schema::create('staff_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->string('type', 30);
            $table->string('title', 120)->nullable();
            $table->text('number')->nullable();
            $table->char('number_hash', 64)->nullable()->index();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            // The file, encrypted before it reaches the private disk.
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->string('note', 300)->nullable();
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            // Replaced or withdrawn documents are archived with a reason, never deleted.
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('archive_reason', 300)->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('staff_documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'archived_at']);
            $table->index(['archived_at', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_documents');
        Schema::dropIfExists('staff_invitations');
        Schema::dropIfExists('staff_profiles');
    }
};
