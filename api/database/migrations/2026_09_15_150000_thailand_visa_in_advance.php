<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thailand: the visa is required in advance, not on arrival (the client, 2026-09-15). Nepal stays on arrival. Travellers'
 * visa and insurance on Thailand trips now default to pending wherever staff haven't already set a status — the default
 * is worked out from the destination (TravellerDocuments::defaultStatus), so no booking row is rewritten. Recorded in the
 * audit log as a system change. Later changes are made on the Destinations card.
 */
return new class extends Migration
{
    public function up(): void
    {
        $thailand = DB::table('destinations')->where('slug', 'thailand')->first();
        if ($thailand === null || ! $thailand->visa_on_arrival) {
            return;
        }

        DB::table('destinations')->where('id', $thailand->id)->update(['visa_on_arrival' => false, 'updated_at' => now()]);
        DB::table('audit_logs')->insert([
            'action' => 'cms.destination.updated',
            'auditable_type' => 'destination',
            'auditable_id' => $thailand->id,
            'changes' => json_encode(['visa_on_arrival' => ['from' => true, 'to' => false], 'reason' => 'Visa required in advance (client decision, 2026-09-15)']),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // A decision, not a schema change: undone on the Destinations card, never by rolling back.
    }
};
