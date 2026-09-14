<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Models\AuditLog;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingStateMachine;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ContentSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §7 and §10: permissions sync, booking numbering, lead stage and the paid-amount check. */
class Phase5FoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function permissions_sync_grants_new_matrix_permissions_never_removes_one_and_respects_an_admin_revocation(): void
    {
        $this->staff('admin');
        $admin = Role::findByName('admin', 'staff');
        $agent = Role::findByName('sales_agent', 'staff');
        // A live database from before Phase 5: the roles lack the new permissions, an admin removed one from sales agents
        // and added an extra one to them.
        $admin->revokePermissionTo(['records.assign', 'air_inquiries.view']);
        $agent->revokePermissionTo(['air_inquiries.view', 'quotations.view_own']);
        $agent->givePermissionTo('payments.view');
        AuditLog::query()->create(['action' => 'role.permission_revoked', 'auditable_type' => $agent->getMorphClass(), 'auditable_id' => $agent->id, 'changes' => ['permission' => 'quotations.view_own']]);

        $this->assertSame(Command::INVALID, Artisan::call('permissions:sync'));
        $this->assertSame(0, Artisan::call('permissions:sync', ['--add-only' => true]));

        $this->assertTrue($admin->fresh()->hasPermissionTo('records.assign') && $admin->fresh()->hasPermissionTo('air_inquiries.view'));
        $this->assertTrue($agent->fresh()->hasPermissionTo('air_inquiries.view'));
        $this->assertFalse($agent->fresh()->hasPermissionTo('quotations.view_own'), 'an admin\'s revocation stands');
        $this->assertTrue($agent->fresh()->hasPermissionTo('payments.view'), 'nothing is removed');
        $this->assertSame(3, AuditLog::query()->where('action', 'role.permission_granted')->count());

        Artisan::call('permissions:sync', ['--add-only' => true]);
        $this->assertSame(3, AuditLog::query()->where('action', 'role.permission_granted')->count(), 'a second run changes nothing');
    }

    #[Test]
    public function booking_references_run_on_one_counter_across_months(): void
    {
        $this->seed(ContentSeeder::class);
        Carbon::setTestNow('2026-09-30 17:00:00'); // 23:00 in Dhaka: still September there
        $september = app(BookingCreator::class)->create($this->request('01711000001'))['booking'];
        Carbon::setTestNow('2026-09-30 18:30:00'); // 00:30 on 1 October in Dhaka
        $october = app(BookingCreator::class)->create($this->request('01711000002'))['booking'];
        Carbon::setTestNow();

        $this->assertSame(['BH-2609-001', 'BH-2610-002'], [$september->reference, $october->reference]);
    }

    #[Test]
    public function a_lead_becomes_a_customer_with_the_first_confirmed_booking(): void
    {
        $this->seed(ContentSeeder::class);
        $staff = $this->staff('admin');
        $booking = app(BookingCreator::class)->create($this->request('01711000001'))['booking'];
        $this->assertSame('lead', DB::table('customers')->where('id', $booking->customer_id)->value('stage'));

        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 76500, 'cash', 'Full', null, $staff));
        app(BookingStateMachine::class)->confirm($booking, $staff);

        $this->assertSame('customer', DB::table('customers')->where('id', $booking->customer_id)->value('stage'));
    }

    #[Test]
    public function a_paid_amount_that_differs_from_the_cash_book_is_reported_to_whoever_sees_the_company_balance(): void
    {
        Mail::fake();
        $this->seed(ContentSeeder::class);
        $staff = $this->staff('admin');
        $accountant = $this->staff('accountant');
        $agent = $this->staff('sales_agent');
        $booking = app(BookingCreator::class)->create($this->request('01711000001'))['booking'];
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 20000, 'cash', 'Advance', null, $staff));

        $this->assertSame(0, Artisan::call('bookings:check-paid'));
        Mail::assertNothingSent();

        DB::table('bookings')->where('id', $booking->id)->update(['paid_amount' => 25000]); // what the check exists to catch
        $this->assertSame(1, Artisan::call('bookings:check-paid'));
        $this->assertStringContainsString($booking->reference, Artisan::output());
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo($accountant->email) && str_contains($mail->alert, $booking->reference));
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo($staff->email));
        Mail::assertNotSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo($agent->email));
    }

    private function request(string $phone): BookingRequest
    {
        return new BookingRequest('nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 1, 'twin', [], [
            ['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '88'.$phone, 'email' => "t{$phone}@example.test"],
        ], 76500, 'en', 'website_form', true);
    }
}
