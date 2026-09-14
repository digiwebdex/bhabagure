<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-6-customer-portal.md §3.3, §4: per traveller, the passport scan and photo customers upload (reviewed by
 * staff) and the visa and insurance statuses staff set. One row per traveller and kind holds the current state; the
 * audit log keeps the history. Files are encrypted on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traveller_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_traveller_id')->constrained('booking_travellers')->cascadeOnDelete();
            // passport_scan · photo · visa · insurance
            $table->string('kind', 20);
            // uploads: uploaded · verified · rejected — visa and insurance: pending · issued · not_required
            $table->string('status', 20);
            $table->string('disk', 20)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            // Staff text the customer sees: "Nepal — visa on arrival", "Green Delta", or why an upload was rejected.
            $table->string('note', 300)->nullable();
            // booking (the website booking's scan) · portal · staff
            $table->string('source', 10)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_traveller_id', 'kind']);
            $table->index(['status', 'uploaded_at']);
        });

        // Scans uploaded with a website booking wait for staff review like a portal upload.
        DB::table('passport_scans')->whereNotNull('booking_traveller_id')->orderBy('id')->get()
            ->unique('booking_traveller_id')
            ->each(fn (object $scan) => DB::table('traveller_documents')->insert([
                'booking_traveller_id' => $scan->booking_traveller_id, 'kind' => 'passport_scan', 'status' => 'uploaded',
                'disk' => $scan->disk, 'path' => $scan->path, 'mime' => $scan->mime, 'bytes' => $scan->bytes, 'source' => 'booking',
                'uploaded_at' => $scan->created_at, 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('traveller_documents');
    }
};
