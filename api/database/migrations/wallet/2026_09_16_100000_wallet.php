<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The super admin wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8), in its own database: run only as
 * `php artisan migrate --database=wallet --path=database/migrations/wallet`. Nothing here references the company
 * database — a staff id is a plain number, never a foreign key — and nothing there references this one.
 */
return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $schema = Schema::connection('wallet');

        // Where money came from or went to: the proprietor's businesses, entered in the wallet itself. Only these two
        // are seeded, so no business name is written into the code.
        $schema->create('wallet_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        DB::connection('wallet')->table('wallet_sources')->insert([
            ['name' => 'ব্যক্তিগত · Personal', 'sort_order' => 900, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'অন্যান্য · Other', 'sort_order' => 950, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Saved references, one list per direction.
        $schema->create('wallet_reference_presets', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);
            $table->string('label', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['direction', 'label']);
        });

        // A deal: a total agreed with a company or client, paid as an advance and then what is due.
        $schema->create('wallet_deals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('total', 14, 2);
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('created_by_staff_id');
            $table->timestamps();
        });

        // Every movement of money, append-only: a mistake is undone by a reversal the other way, once.
        $schema->create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            // in · out
            $table->string('direction', 3);
            $table->decimal('amount', 14, 2);
            // entry · deal_advance · deal_payment · reversal
            $table->string('kind', 16);
            $table->foreignId('source_id')->nullable()->constrained('wallet_sources');
            // The source (or deal) as it was named when the money moved.
            $table->string('source_name', 120);
            $table->foreignId('deal_id')->nullable()->constrained('wallet_deals');
            $table->string('reference', 300);
            $table->date('occurred_on');
            // Encrypted with WALLET_KEY on the private disk.
            $table->string('evidence_path', 200)->nullable();
            $table->string('evidence_mime', 60)->nullable();
            $table->string('evidence_name', 160)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('wallet_transactions');
            $table->string('reason', 300)->nullable();
            $table->unsignedBigInteger('created_by_staff_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['occurred_on', 'id']);
            $table->index(['direction', 'id']);
        });

        // The super admin's authenticator app: the secret encrypted with WALLET_KEY, and the last time step used so a
        // code can't be replayed.
        $schema->create('wallet_authenticators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });

        // Sign-in: a short challenge between the password and the code, then a session. Tokens are stored hashed.
        $schema->create('wallet_sessions', function (Blueprint $table) {
            $table->id();
            // challenge · session
            $table->string('kind', 10);
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedTinyInteger('failed_codes')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'kind']);
        });

        // The wallet's own audit trail (sign-ins, failures, reversals, deals), append-only, never in the company's.
        $schema->create('wallet_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('wallet');
        foreach (['wallet_audit_logs', 'wallet_sessions', 'wallet_authenticators', 'wallet_transactions', 'wallet_deals', 'wallet_reference_presets', 'wallet_sources'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
