<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationMessage;
use App\Models\Staff;
use App\Models\StaffDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §4.2: staff documents protected as customer passports are — encrypted file
 * and number, `no-store` — seen only with the permission (not even by their owner), every opening audited, replacements
 * archiving instead of deleting, and expiry statuses, badge and alerts following the date in Dhaka.
 */
class StaffDocumentsTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    #[Test]
    public function a_staff_document_is_encrypted_at_rest_opened_only_with_the_permission_and_every_opening_is_audited(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $owner = $this->staff('sales_agent');

        $this->actingAsApi($admin)->post("/api/v1/admin/staff/{$owner->id}/documents", [
            'type' => 'passport', 'number' => 'BW0912345', 'issued_on' => '2021-01-19', 'expires_on' => now('Asia/Dhaka')->addDays(20)->toDateString(),
            'file' => UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 staff passport scan'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'expiring')->assertJsonPath('data.days_left', 20)->assertJsonPath('data.number', 'BW0912345');
        $document = StaffDocument::query()->sole();

        $this->assertStringNotContainsString('staff passport scan', (string) Storage::disk('local')->get($document->path));
        $this->assertStringNotContainsString('BW0912345', (string) DB::table('staff_documents')->value('number'));

        // The owner, and every role without the permission, gets nothing — the list, the file or the upload.
        foreach ([$owner, $this->staff('accountant'), $this->staff('tour_operator')] as $outsider) {
            $this->actingAsApi($outsider)->getJson('/api/v1/admin/staff-documents')->assertForbidden();
            $this->actingAsApi($outsider)->get("/api/v1/admin/staff-documents/{$document->id}/file")->assertForbidden();
            $this->actingAsApi($outsider)->post("/api/v1/admin/staff/{$outsider->id}/documents", ['type' => 'cv'], ['Accept' => 'application/json'])->assertForbidden();
        }

        $file = $this->actingAsApi($admin)->get("/api/v1/admin/staff-documents/{$document->id}/file")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('%PDF-1.4 staff passport scan', $file->getContent());
        $this->assertSame(1, AuditLog::query()->where('action', 'staff_document.opened')->where('actor_id', $admin->id)->where('auditable_id', $owner->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'staff_document.uploaded')->where('auditable_id', $owner->id)->exists());
        $this->assertFalse(AuditLog::query()->get()->contains(fn (AuditLog $log) => str_contains((string) json_encode($log->changes), 'BW0912345')));

        // A viewer who may see documents but not manage them can open, not upload.
        $viewer = $this->staff('accountant');
        $viewer->givePermissionTo('staff_documents.view');
        $this->actingAsApi($viewer)->getJson('/api/v1/admin/staff-documents')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($viewer)->post("/api/v1/admin/staff/{$owner->id}/documents", ['type' => 'cv'], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAsApi($viewer)->getJson('/api/v1/admin/staff-documents/owners')->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/staff-documents/owners')->assertOk()->assertJsonFragment(['id' => $owner->id, 'name' => $owner->name]);
    }

    #[Test]
    public function a_replacement_archives_the_old_document_with_its_reason_and_nothing_is_deleted(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $owner = $this->staff('sales_agent');
        $old = $this->actingAsApi($admin)->post("/api/v1/admin/staff/{$owner->id}/documents", [
            'type' => 'nid', 'number' => '1994269123456', 'file' => UploadedFile::fake()->image('nid.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'no_expiry')->json('data.id');

        $new = $this->actingAsApi($admin)->post("/api/v1/admin/staff-documents/{$old}/replace", [
            'type' => 'nid', 'number' => '1994269123456', 'note' => 'Smart card', 'file' => UploadedFile::fake()->image('nid-smart.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $archived = StaffDocument::query()->findOrFail($old);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('replaced', $archived->archive_reason);
        $this->assertSame($new, $archived->replaced_by_id);
        $this->assertTrue(Storage::disk('local')->exists($archived->path));
        $this->actingAsApi($admin)->post("/api/v1/admin/staff-documents/{$old}/replace", ['type' => 'nid', 'file' => UploadedFile::fake()->image('again.jpg')], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'document_archived');

        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/staff-documents')->json('meta.total'));
        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/staff-documents?archived=1')->json('meta.total'));
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff-documents/{$new}/archive", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff-documents/{$new}/archive", ['reason' => 'Left the company'])->assertOk()->assertJsonPath('data.archive_reason', 'Left the company');
        $this->assertSame(2, StaffDocument::query()->count());

        // A certificate or "other" needs a title; an expiry can't come before the issue date.
        $this->actingAsApi($admin)->post("/api/v1/admin/staff/{$owner->id}/documents", ['type' => 'certificate', 'file' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAsApi($admin)->post("/api/v1/admin/staff/{$owner->id}/documents", ['type' => 'passport', 'issued_on' => '2026-01-10', 'expires_on' => '2025-01-10', 'file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_on');
    }

    #[Test]
    public function statuses_the_attention_filter_and_the_expiry_alerts_follow_the_date_in_dhaka(): void
    {
        $this->sendNotifications();
        $admin = $this->staff('admin');
        $owner = $this->staff('sales_agent');
        $leaver = $this->staff('sales_agent', ['status' => 'suspended']);
        $expired = $this->document($owner, -1);
        $expiring = $this->document($owner, 30);
        $this->document($owner, 31);
        $this->document($owner, 90);
        $this->document($owner, 91);
        $this->document($owner, null);
        $this->document($leaver, -10);

        $statuses = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/staff-documents?staff_id='.$owner->id)->assertOk()->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['expired', 'expiring', 'no_expiry', 'renew_soon', 'renew_soon', 'valid'], $statuses);
        $attention = $this->actingAsApi($admin)->getJson('/api/v1/admin/staff-documents?status=attention')->assertOk();
        $this->assertEqualsCanonicalizing([$expired->id, $expiring->id], collect($attention->json('data'))->pluck('id')->all());
        $this->assertSame(2, $attention->json('meta.status_counts.attention'));

        // With nobody on the alert's list, everyone who manages staff documents is told — once per document per occasion.
        $this->artisan('staff-documents:remind-expiring')->assertSuccessful();
        $this->artisan('staff-documents:remind-expiring')->assertSuccessful();
        $alerts = NotificationMessage::query()->where('event', 'staff_document_expiring_alert')->where('channel', 'email')->get();
        $this->assertCount(2, $alerts);
        $this->assertTrue($alerts->every(fn (NotificationMessage $message) => $message->to_address === $admin->email));
        $expiringAlert = $alerts->firstWhere('related_id', $expiring->id);
        $this->assertStringContainsString('(৩০ দিন পর)', $expiringAlert->body);
        $this->assertStringContainsString('/vault?status=attention', $expiringAlert->body);
        $this->assertStringNotContainsString('BW', $expiringAlert->body);

        // The day it expires it is alerted again, once.
        $this->travel(30)->days();
        $this->artisan('staff-documents:remind-expiring')->assertSuccessful();
        $this->artisan('staff-documents:remind-expiring')->assertSuccessful();
        $this->assertSame(2, NotificationMessage::query()->where('event', 'staff_document_expiring_alert')->where('channel', 'email')->where('related_id', $expiring->id)->count());
        $this->assertStringContainsString('(আজ)', NotificationMessage::query()->where('event', 'staff_document_expiring_alert')->where('related_id', $expiring->id)->latest('id')->firstOrFail()->body);
    }

    private function document(Staff $owner, ?int $daysLeft): StaffDocument
    {
        return StaffDocument::query()->create([
            'staff_id' => $owner->id, 'type' => 'passport', 'number' => 'BW'.random_int(1000000, 9999999),
            'expires_on' => $daysLeft === null ? null : now('Asia/Dhaka')->addDays($daysLeft)->toDateString(),
            'disk' => 'local', 'path' => 'staff-documents/test.enc', 'mime' => 'application/pdf', 'bytes' => 1,
        ]);
    }
}
