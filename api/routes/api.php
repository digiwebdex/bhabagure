<?php

use App\Http\Controllers\Api\V1\Admin\AccountController;
use App\Http\Controllers\Api\V1\Admin\AccountingReportController;
use App\Http\Controllers\Api\V1\Admin\AirInquiryController;
use App\Http\Controllers\Api\V1\Admin\AssignableStaffController;
use App\Http\Controllers\Api\V1\Admin\AttendanceController;
use App\Http\Controllers\Api\V1\Admin\AttendanceDeviceController;
use App\Http\Controllers\Api\V1\Admin\BlogCategoryController;
use App\Http\Controllers\Api\V1\Admin\BlogPostController;
use App\Http\Controllers\Api\V1\Admin\BonusController;
use App\Http\Controllers\Api\V1\Admin\BookingController;
use App\Http\Controllers\Api\V1\Admin\BookingTicketController;
use App\Http\Controllers\Api\V1\Admin\CatalogueController;
use App\Http\Controllers\Api\V1\Admin\CustomerAccountsController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DealController;
use App\Http\Controllers\Api\V1\Admin\DepartureController;
use App\Http\Controllers\Api\V1\Admin\DocumentReviewController;
use App\Http\Controllers\Api\V1\Admin\DownloadLogController;
use App\Http\Controllers\Api\V1\Admin\GalleryItemController;
use App\Http\Controllers\Api\V1\Admin\HotelInquiryController;
use App\Http\Controllers\Api\V1\Admin\InvoiceBuilderController;
use App\Http\Controllers\Api\V1\Admin\JournalController;
use App\Http\Controllers\Api\V1\Admin\LeaveRequestController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\MyAttendanceController;
use App\Http\Controllers\Api\V1\Admin\MyCommissionController;
use App\Http\Controllers\Api\V1\Admin\MyRecordController;
use App\Http\Controllers\Api\V1\Admin\NavCountController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\Admin\PackageImageController;
use App\Http\Controllers\Api\V1\Admin\PaymentsController;
use App\Http\Controllers\Api\V1\Admin\PayrollController;
use App\Http\Controllers\Api\V1\Admin\PricingController;
use App\Http\Controllers\Api\V1\Admin\ProfileWhatsAppController;
use App\Http\Controllers\Api\V1\Admin\QuotationController;
use App\Http\Controllers\Api\V1\Admin\ReferencePresetController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SearchController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingController;
use App\Http\Controllers\Api\V1\Admin\StaffBookingController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StaffDocumentController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController;
use App\Http\Controllers\Api\V1\Admin\TeamMemberController;
use App\Http\Controllers\Api\V1\Admin\VisaServiceController;
use App\Http\Controllers\Api\V1\Agent\AttendanceAgentController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Auth\StaffInvitationController;
use App\Http\Controllers\Api\V1\Payments\FakeGatewayController;
use App\Http\Controllers\Api\V1\Payments\SslCommerzCallbackController;
use App\Http\Controllers\Api\V1\Portal\DownloadController;
use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Api\V1\Portal\PortalPaymentController;
use App\Http\Controllers\Api\V1\Portal\PortalProfileController;
use App\Http\Controllers\Api\V1\Portal\PortalQuotationController;
use App\Http\Controllers\Api\V1\Portal\PortalSupportController;
use App\Http\Controllers\Api\V1\Portal\PortalTripController;
use App\Http\Controllers\Api\V1\Public\PublicBookingController;
use App\Http\Controllers\Api\V1\Public\PublicContentController;
use App\Http\Controllers\Api\V1\Public\PublicFormController;
use App\Http\Controllers\Api\V1\Public\PublicInvoiceController;
use App\Http\Controllers\Api\V1\Public\PublicPassportScanController;
use App\Http\Controllers\Api\V1\Public\PublicQuotationController;
use App\Http\Controllers\Api\V1\Webhooks\WaSenderWebhookController;
use App\Models\TravellerDocument;
use Illuminate\Support\Facades\Route;

/*
 * All routes are under /api/v1. Route parameters named {id} are numeric database ids; public content is
 * addressed by slug. See docs/phase-2-cms-api.md for the endpoint list and permissions.
 */
Route::prefix('v1')->group(function () {

    // ── Authentication ───────────────────────────────────────────────────────────────────────────────
    Route::prefix('staff/auth')->controller(StaffAuthController::class)->group(function () {
        Route::post('login', 'login');
        Route::post('refresh', 'refresh')->middleware('throttle:auth-refresh');
        Route::post('logout', 'logout');
        Route::middleware('auth:staff')->group(function () {
            Route::get('me', 'me');
            Route::post('change-password', 'changePassword');
        });
        // Invitation and password-reset links (docs/phase-7-hr-attendance-bonus-wallet.md §4.1).
        Route::middleware('throttle:staff-invitations')->controller(StaffInvitationController::class)->group(function () {
            Route::post('invitation/check', 'check');
            Route::post('invitation/accept', 'accept');
        });
    });

    // Customer portal: sign-in by one-time code only (docs/phase-6-customer-portal.md §3.1).
    Route::prefix('customer/auth')->controller(CustomerAuthController::class)->group(function () {
        Route::post('code', 'sendCode')->middleware('throttle:customer-codes');
        Route::post('verify', 'verify')->middleware('throttle:customer-verify');
        Route::post('refresh', 'refresh')->middleware('throttle:auth-refresh');
        Route::post('logout', 'logout');
        Route::get('me', 'me')->middleware('auth:customer');
    });

    // ── Customer portal (docs/phase-6-customer-portal.md): only the signed-in customer's own records ─────
    Route::prefix('portal')->middleware(['auth:customer', 'portal.access', 'throttle:portal'])->group(function () {
        Route::get('trips', [PortalTripController::class, 'index']);
        Route::get('trips/{reference}', [PortalTripController::class, 'show']);
        Route::get('tickets/{ticketId}/file', [PortalTripController::class, 'ticketFile'])->whereNumber('ticketId');
        Route::get('payments', [PortalPaymentController::class, 'index']);
        // Brochure and visa-requirements PDFs from the website, logged (Phase 8 §4.E).
        Route::controller(DownloadController::class)->middleware('throttle:downloads')->group(function () {
            Route::get('downloads/packages/{slug}', 'package');
            Route::get('downloads/visas/{slug}', 'visa');
        });
        Route::get('quotations', [PortalQuotationController::class, 'index']);
        Route::get('quotations/{number}', [PortalQuotationController::class, 'show']);
        Route::post('quotations/{number}/accept', [PortalQuotationController::class, 'accept']);
        Route::controller(PortalDocumentController::class)->group(function () {
            Route::get('documents', 'index');
            Route::post('travellers/{travellerId}/documents/{kind}', 'upload')->whereNumber('travellerId')->whereIn('kind', TravellerDocument::UPLOADS)->middleware('throttle:portal-uploads');
            Route::get('travellers/{travellerId}/documents/{kind}/file', 'file')->whereNumber('travellerId')->whereIn('kind', TravellerDocument::UPLOADS);
            Route::put('travellers/{travellerId}/passport', 'passport')->whereNumber('travellerId');
        });
        Route::controller(PortalSupportController::class)->group(function () {
            Route::get('support', 'index');
            Route::post('support', 'store')->middleware('throttle:portal-support');
            Route::get('support/{number}', 'show');
            Route::post('support/{number}/messages', 'message')->middleware('throttle:portal-support');
        });
        Route::controller(PortalProfileController::class)->group(function () {
            Route::get('profile', 'show');
            Route::put('profile', 'update')->middleware('throttle:portal-support');
            Route::post('profile/email', 'requestEmail')->middleware('throttle:customer-codes');
            Route::post('profile/email/confirm', 'confirmEmail')->middleware('throttle:customer-verify');
            Route::post('profile/phone/code', 'requestPhoneCode')->middleware('throttle:customer-codes');
            Route::post('profile/phone', 'changePhone')->middleware('throttle:customer-verify');
            Route::get('nps', 'nps');
            Route::post('trips/{reference}/nps', 'answerNps')->middleware('throttle:portal-support');
        });
    });

    // ── Public website content (read) ────────────────────────────────────────────────────────────────
    Route::prefix('public')->group(function () {
        Route::controller(PublicContentController::class)->middleware('throttle:public-read')->group(function () {
            Route::get('destinations', 'destinations');
            Route::get('packages', 'packages');
            Route::get('packages/{slug}', 'package');
            Route::get('departures', 'departures');
            Route::get('posts', 'posts');
            Route::get('posts/{slug}', 'post');
            Route::get('team', 'team');
            Route::get('reviews', 'reviews');
            Route::get('gallery', 'gallery');
            Route::get('visas', 'visas');
            Route::get('pricing', 'pricing');
            Route::get('settings', 'settings');
        });

        Route::controller(PublicFormController::class)->middleware('throttle:public-forms')->group(function () {
            Route::post('inquiries', 'inquiry');
            Route::post('air-quotes', 'airQuote');
            Route::post('hotel-quotes', 'hotelQuote');
            Route::post('newsletter', 'subscribe');
        });

        // Booking (docs/phase-3-booking.md). Guests pass the booking's private token in X-Booking-Token.
        Route::controller(PublicBookingController::class)->group(function () {
            Route::post('bookings', 'store')->middleware('throttle:public-bookings');
            Route::get('bookings/{reference}', 'show')->middleware('throttle:public-read');
            Route::post('bookings/{reference}/payments', 'pay')->middleware('throttle:public-bookings');
        });
        Route::post('passport-scans', [PublicPassportScanController::class, 'store'])->middleware('throttle:passport-scans');

        Route::controller(PublicInvoiceController::class)->middleware('throttle:public-read')->group(function () {
            Route::get('invoices/{token}', 'show');
            Route::get('invoices/{token}/pdf', 'pdf');
        });
        Route::controller(PublicQuotationController::class)->middleware('throttle:public-read')->group(function () {
            Route::get('quotations/{token}', 'show');
            Route::get('quotations/{token}/pdf', 'pdf');
        });

        // Signed-link actions: separate from the form limit so a shared office IP can always unsubscribe.
        Route::controller(PublicFormController::class)->middleware('throttle:newsletter-unsubscribe')->group(function () {
            Route::get('newsletter/unsubscribe/{token}', 'unsubscribeStatus');
            Route::post('newsletter/unsubscribe/{token}', 'unsubscribe');
        });
    });

    // ── Payment gateway callbacks (server-to-server IPN, and the customer's browser) ────────────────
    Route::prefix('payments')->middleware('throttle:payment-callbacks')->group(function () {
        Route::controller(SslCommerzCallbackController::class)->prefix('sslcommerz')->group(function () {
            Route::post('ipn', 'ipn');
            Route::post('success', 'success');
            Route::post('fail', 'fail');
            Route::post('cancel', 'cancel');
        });
        Route::get('fake-gateway/{tranId}', [FakeGatewayController::class, 'show']);
    });

    // ── Provider webhooks ─────────────────────────────────────────────────────────────────────────────
    // WaSenderAPI delivery status, session status and STOP replies. Refused without the shared secret.
    Route::post('webhooks/wasender', WaSenderWebhookController::class)->middleware('throttle:webhooks');

    // ── Office attendance agent ──────────────────────────────────────────────────────────────────────
    // docs/phase-7-hr-attendance-bonus-wallet.md §5.2: a device token, not a staff session.
    Route::prefix('attendance-agent')->middleware(['attendance.agent', 'throttle:attendance-agent'])->controller(AttendanceAgentController::class)->group(function () {
        Route::post('check-in', 'checkIn');
        Route::post('device', 'report');
        Route::post('punches', 'punches');
    });

    // ── CMS (staff) ──────────────────────────────────────────────────────────────────────────────────
    Route::prefix('admin')->middleware(['auth:staff', 'staff.can-work'])->group(function () {

        Route::middleware('permission:packages.manage|cms.manage,staff')->controller(MediaController::class)->group(function () {
            Route::get('media', 'index');
            Route::post('media', 'store')->middleware('throttle:media-upload');
            Route::patch('media/{id}', 'update')->whereNumber('id');
            Route::delete('media/{id}', 'destroy')->whereNumber('id');
        });

        Route::middleware('permission:packages.manage,staff')->group(function () {
            Route::controller(PackageController::class)->group(function () {
                Route::get('packages', 'index');
                Route::post('packages', 'store');
                Route::put('packages/order', 'reorder');
                Route::get('packages/{id}', 'show')->whereNumber('id');
                Route::put('packages/{id}', 'update')->whereNumber('id');
                Route::delete('packages/{id}', 'destroy')->whereNumber('id');
                Route::post('packages/{id}/publish', 'publish')->whereNumber('id');
                Route::post('packages/{id}/unpublish', 'unpublish')->whereNumber('id');
                Route::post('packages/{id}/archive', 'archive')->whereNumber('id');
            });

            Route::controller(PackageImageController::class)->group(function () {
                Route::post('packages/{packageId}/images', 'store')->whereNumber('packageId');
                Route::put('packages/{packageId}/images/order', 'reorder')->whereNumber('packageId');
                Route::delete('packages/{packageId}/images/{imageId}', 'destroy')->whereNumber(['packageId', 'imageId']);
            });

            Route::controller(DepartureController::class)->group(function () {
                Route::get('packages/{packageId}/departures', 'index')->whereNumber('packageId');
                Route::post('packages/{packageId}/departures', 'store')->whereNumber('packageId');
                Route::put('departures/{id}', 'update')->whereNumber('id');
                Route::delete('departures/{id}', 'destroy')->whereNumber('id');
            });

            Route::controller(CatalogueController::class)->group(function () {
                Route::get('destinations', 'destinations');
                Route::post('destinations', 'storeDestination');
                Route::put('destinations/{id}', 'updateDestination')->whereNumber('id');
                Route::get('tags', 'tags');
            });
        });

        // A staff member's own WhatsApp number (verified with a code) — for alerts and template test sends.
        Route::controller(ProfileWhatsAppController::class)->group(function () {
            Route::get('profile/whatsapp', 'show');
            Route::post('profile/whatsapp', 'start');
            Route::post('profile/whatsapp/verify', 'verify');
            Route::delete('profile/whatsapp', 'destroy');
        });

        // WhatsApp and email notifications (docs/phase-4-whatsapp.md). Numbers always come from records.
        Route::post('notifications/whatsapp', [NotificationController::class, 'send'])->middleware('permission:notifications.send,staff');
        Route::post('customers/{customerId}/whatsapp-opt-out', [NotificationController::class, 'setCustomerOptOut'])->whereNumber('customerId');
        Route::middleware('permission:notifications.manage,staff')->controller(NotificationController::class)->group(function () {
            Route::get('notifications/overview', 'overview');
            Route::get('notifications', 'index');
            Route::get('notification-templates', 'templates');
            Route::put('notification-templates/{id}', 'updateTemplate')->whereNumber('id');
            Route::post('notification-templates/{id}/test', 'testTemplate')->whereNumber('id');
            Route::post('notification-templates/{id}/preview', 'previewTemplate')->whereNumber('id');
            Route::get('notification-settings', 'settings');
            Route::put('notification-settings', 'updateSettings');
        });

        // Staff records, roles and staff documents (docs/phase-7-hr-attendance-bonus-wallet.md §4).
        Route::get('profile/record', MyRecordController::class);
        Route::middleware('permission:staff.manage,staff')->controller(StaffController::class)->group(function () {
            Route::get('staff', 'index');
            Route::get('staff/options', 'options');
            Route::post('staff', 'store');
            Route::get('staff/{id}', 'show')->whereNumber('id');
            Route::put('staff/{id}', 'update')->whereNumber('id');
            Route::put('staff/{id}/role', 'changeRole')->whereNumber('id');
            Route::post('staff/{id}/suspend', 'suspend')->whereNumber('id');
            Route::post('staff/{id}/reactivate', 'reactivate')->whereNumber('id');
            Route::post('staff/{id}/invitation', 'invite')->whereNumber('id');
            Route::post('staff/{id}/password-reset', 'passwordReset')->whereNumber('id');
        });
        Route::middleware('permission:system.roles_manage,staff')->controller(RoleController::class)->group(function () {
            Route::get('roles', 'index');
            Route::post('roles', 'store');
            Route::put('roles/{id}', 'update')->whereNumber('id');
            Route::put('roles/{id}/permissions', 'setPermission')->whereNumber('id');
            Route::delete('roles/{id}', 'destroy')->whereNumber('id');
        });
        // Uploading, replacing and archiving also need staff_documents.manage (checked in the controller).
        Route::middleware('permission:staff_documents.view,staff')->controller(StaffDocumentController::class)->group(function () {
            Route::get('staff-documents', 'index');
            Route::get('staff-documents/owners', 'owners');
            Route::get('staff-documents/{id}/file', 'file')->whereNumber('id');
            Route::post('staff/{id}/documents', 'store')->whereNumber('id')->middleware('throttle:media-upload');
            Route::post('staff-documents/{id}/replace', 'replace')->whereNumber('id')->middleware('throttle:media-upload');
            Route::post('staff-documents/{id}/archive', 'archive')->whereNumber('id');
        });

        // Attendance (docs/phase-7-hr-attendance-bonus-wallet.md §5). Changes also need attendance.manage (checked in the
        // controllers); leave decisions are attendance.manage's alone.
        Route::middleware('permission:attendance.view_all|attendance.manage,staff')->group(function () {
            Route::controller(AttendanceDeviceController::class)->group(function () {
                Route::get('attendance/devices', 'index');
                Route::post('attendance/devices', 'store');
                Route::post('attendance/devices/{id}/rotate-token', 'rotateToken')->whereNumber('id');
                Route::post('attendance/devices/{id}/revoke', 'revoke')->whereNumber('id');
                Route::post('attendance/devices/{id}/commands', 'command')->whereNumber('id');
                Route::post('attendance/devices/{id}/allow-replacement', 'allowReplacement')->whereNumber('id');
                Route::get('attendance/devices/{id}/users', 'users')->whereNumber('id');
                Route::put('attendance/device-users/{userId}', 'mapUser')->whereNumber('userId');
            });
            Route::controller(AttendanceController::class)->group(function () {
                Route::get('attendance/month', 'month');
                Route::get('attendance/staff/{id}', 'staff')->whereNumber('id');
                Route::get('attendance/rules', 'rules');
                Route::put('attendance/rules', 'saveRules');
                Route::get('attendance/holidays', 'holidayList');
                Route::post('attendance/holidays', 'storeHoliday');
                Route::delete('attendance/holidays/{id}', 'destroyHoliday')->whereNumber('id');
                Route::post('attendance/corrections', 'correct');
                Route::post('attendance/corrections/{id}/reverse', 'reverseCorrection')->whereNumber('id');
            });
        });
        Route::middleware('permission:attendance.manage,staff')->controller(LeaveRequestController::class)->group(function () {
            Route::get('leave-requests', 'index');
            Route::post('leave-requests', 'store');
            Route::post('leave-requests/{id}/approve', 'approve')->whereNumber('id');
            Route::post('leave-requests/{id}/reject', 'reject')->whereNumber('id');
            Route::post('leave-requests/{id}/revoke', 'revoke')->whereNumber('id');
        });
        // Everyone's own attendance and leave: no permission, and no staff id to point elsewhere.
        Route::controller(MyAttendanceController::class)->group(function () {
            Route::get('profile/attendance', 'month');
            Route::get('profile/leave-requests', 'leave');
            Route::post('profile/leave-requests', 'file');
            Route::post('profile/leave-requests/{id}/cancel', 'cancel')->whereNumber('id');
        });

        // Salary from attendance (docs/phase-7-hr-attendance-bonus-wallet.md §6). Changes also need payroll.manage, and
        // reopening a finalised month the super admin (checked in the controller).
        Route::middleware('permission:payroll.view|payroll.manage,staff')->controller(PayrollController::class)->group(function () {
            Route::get('payroll', 'sheet');
            Route::post('payroll/{month}/adjustments', 'addAdjustment')->where('month', '\d{4}-\d{2}');
            Route::delete('payroll-adjustments/{id}', 'removeAdjustment')->whereNumber('id');
            Route::post('payroll/{month}/finalise', 'finalise')->where('month', '\d{4}-\d{2}');
            Route::post('payroll/{month}/reopen', 'reopen')->where('month', '\d{4}-\d{2}');
            Route::post('payroll-items/{id}/pay', 'pay')->whereNumber('id')->middleware('throttle:media-upload');
            Route::get('payroll-items/{id}/payslip', 'payslip')->whereNumber('id');
            Route::get('staff/{id}/salaries', 'salaries')->whereNumber('id');
            Route::post('staff/{id}/salaries', 'setSalary')->whereNumber('id');
        });
        // Each person's own finalised payslips.
        Route::controller(PayrollController::class)->group(function () {
            Route::get('profile/payslips', 'myPayslips');
            Route::get('profile/payslips/{id}', 'myPayslip')->whereNumber('id');
        });

        // Bonus accounts and withdrawals (docs/phase-7-hr-attendance-bonus-wallet.md §7). Changes also need bonus.manage
        // (checked in the controller).
        Route::middleware('permission:bonus.manage|commission.view_all,staff')->controller(BonusController::class)->group(function () {
            Route::get('staff/{id}/bonus', 'ledger')->whereNumber('id');
            Route::post('staff/{id}/bonus/credits', 'credit')->whereNumber('id');
            Route::post('bonus-entries/{id}/reverse', 'reverse')->whereNumber('id');
            Route::get('bonus-withdrawals', 'withdrawals');
            Route::post('bonus-withdrawals/{id}/approve', 'approve')->whereNumber('id');
            Route::post('bonus-withdrawals/{id}/reject', 'reject')->whereNumber('id');
            Route::post('bonus-withdrawals/{id}/pay', 'pay')->whereNumber('id')->middleware('throttle:media-upload');
        });
        // My commission: the signed-in person's own figures and requests; no staff id to point elsewhere (Phase 5 §4.8).
        Route::middleware('permission:commission.view_own|commission.view_all,staff')->controller(MyCommissionController::class)->group(function () {
            Route::get('profile/commission', 'show');
            Route::post('profile/commission/withdrawals', 'requestWithdrawal');
            Route::post('profile/commission/withdrawals/{id}/cancel', 'cancelWithdrawal')->whereNumber('id');
        });

        // Sidebar badges, derived from the same scoped queries as their lists (docs/phase-5-admin-core.md §3.1).
        Route::get('nav-counts', NavCountController::class);
        // The Dashboard (§4.2): every widget computed now, each behind its permission.
        Route::get('dashboard', DashboardController::class);
        Route::get('assignable-staff', AssignableStaffController::class);
        // Header search: top five of each kind, through the same visibility scopes (§4.1).
        Route::get('search', SearchController::class)->middleware('throttle:public-read');

        // Customers & leads (§4.4). Per-action permissions and ownership are checked in the controller.
        // Brochure and visa PDFs customers downloaded (Phase 8 §4.E).
        Route::get('downloads', [DownloadLogController::class, 'index'])->middleware('permission:downloads.view,staff');

        Route::middleware('permission:customers.view,staff')->controller(CustomerController::class)->group(function () {
            Route::get('customers', 'index');
            Route::get('customers/board', 'board');
            Route::post('customers', 'store');
            Route::get('customers/{id}', 'show')->whereNumber('id');
            Route::put('customers/{id}', 'update')->whereNumber('id');
            Route::delete('customers/{id}', 'destroy')->whereNumber('id');
            Route::post('customers/{id}/contacts', 'logContact')->whereNumber('id');
            Route::post('customers/{id}/lost', 'markLost')->whereNumber('id');
            Route::delete('customers/{id}/lost', 'reopen')->whereNumber('id');
            Route::post('customers/{id}/claim', 'claim')->whereNumber('id');
            Route::post('customers/{id}/portal-access', 'portalAccess')->whereNumber('id');
            Route::post('customers/{id}/assign', 'assign')->whereNumber('id');
        });

        // Quotation-request queues: Air ticketing (§4.7) and Hotel requests (Phase 8 §4.B). Per-action permissions are
        // checked in the controller (WorksQuoteRequests).
        foreach (['air-inquiries' => [AirInquiryController::class, 'air_inquiries'], 'hotel-inquiries' => [HotelInquiryController::class, 'hotel_inquiries']] as $path => [$controller, $permission]) {
            Route::middleware("permission:{$permission}.view,staff")->controller($controller)->group(function () use ($path) {
                Route::get($path, 'index');
                Route::get("{$path}/{id}", 'show')->whereNumber('id');
                Route::post("{$path}/{id}/claim", 'claim')->whereNumber('id');
                Route::post("{$path}/{id}/assign", 'assign')->whereNumber('id');
                Route::post("{$path}/{id}/reply", 'reply')->whereNumber('id');
                Route::post("{$path}/{id}/quoted", 'markQuoted')->whereNumber('id');
                Route::delete("{$path}/{id}/quoted", 'undoQuoted')->whereNumber('id');
            });
        }

        // The Support queue: tickets from the customer portal (docs/phase-6-customer-portal.md §3.5).
        Route::middleware('permission:support.manage,staff')->controller(SupportTicketController::class)->group(function () {
            Route::get('support-tickets', 'index');
            Route::get('support-tickets/{id}', 'show')->whereNumber('id');
            Route::post('support-tickets/{id}/replies', 'reply')->whereNumber('id');
            Route::post('support-tickets/{id}/close', 'close')->whereNumber('id');
        });

        // Quotations (§4.5). Per-action permissions are checked in the controller.
        Route::middleware('permission:quotations.view_all|quotations.view_own,staff')->controller(QuotationController::class)->group(function () {
            Route::get('quotations', 'index');
            Route::get('quotations/summary', 'summary');
            Route::get('quotations/options', 'options');
            Route::post('quotations', 'store');
            Route::get('quotations/{id}', 'show')->whereNumber('id');
            Route::put('quotations/{id}', 'update')->whereNumber('id');
            Route::delete('quotations/{id}', 'destroy')->whereNumber('id');
            Route::get('quotations/{id}/print', 'print')->whereNumber('id');
            Route::get('quotations/{id}/pdf', 'pdf')->whereNumber('id');
            Route::post('quotations/{id}/convert', 'convert')->whereNumber('id');
            Route::post('quotations/{id}/assign', 'assign')->whereNumber('id');
            Route::post('quotations/{id}/{action}', 'transition')->whereNumber('id')->whereIn('action', ['send', 'accept', 'decline', 'withdraw', 'revise']);
        });

        // Payments & invoices (§4.6): cash book, method cards, company balance, manual entries, online payment review,
        // opening balances, saved references and deals. Per-action permissions are checked in the controllers.
        Route::middleware('permission:payments.view,staff')->group(function () {
            Route::controller(PaymentsController::class)->group(function () {
                Route::get('payments/summary', 'summary');
                Route::get('payments/balance', 'balance');
                Route::get('payments/options', 'options');
                Route::get('cash-book', 'cashBook');
                Route::get('cash-book/{id}/evidence', 'evidence')->whereNumber('id');
                Route::post('cash-book/{id}/reverse', 'reverse')->whereNumber('id');
                Route::post('cash-entries', 'store')->middleware('throttle:media-upload');
                Route::post('opening-balances', 'storeOpeningBalance');
                // Money moved between the company's own accounts — cash banked, a float handed over (§6).
                Route::post('transfers', 'transfer');
                // VAT collected from customers, handed over to the government (§7).
                Route::post('vat-payments', 'payVat')->middleware('throttle:media-upload');
                // Ticking off an entry someone has checked; the entry itself is never touched (§7).
                Route::post('cash-book/{id}/approve', 'approve')->whereNumber('id');
                Route::get('payment-attempts/review', 'reviewIndex');
                Route::post('payment-attempts/{id}/review', 'markReviewed')->whereNumber('id');
            });
            Route::controller(ReferencePresetController::class)->group(function () {
                Route::get('reference-presets', 'index');
                Route::post('reference-presets', 'store');
                Route::delete('reference-presets/{id}', 'destroy')->whereNumber('id');
            });
            // Invoices staff write themselves (docs/phase-9-accounts.md §5): the list, the builder, and issuing a draft.
            // Paying and voiding stay on the deal endpoints below, which is where the ledger already handles them.
            Route::controller(InvoiceBuilderController::class)->group(function () {
                Route::get('invoices', 'index');
                Route::get('invoices/{id}', 'show')->whereNumber('id');
                Route::post('invoices', 'store')->middleware('permission:invoices.manage,staff');
                Route::put('invoices/{id}', 'update')->whereNumber('id')->middleware('permission:invoices.manage,staff');
                Route::post('invoices/{id}/issue', 'issue')->whereNumber('id')->middleware('permission:invoices.manage,staff');
                Route::delete('invoices/{id}', 'destroy')->whereNumber('id')->middleware('permission:invoices.manage,staff');
                // What is still owed, by SMS or email, written by the staff member (§5).
                Route::post('invoices/{id}/reminders', 'remind')->whereNumber('id')->middleware('throttle:notifications');
            });
            // Customers as the books see them: invoiced, paid and still owed, and one customer's invoices (§9).
            Route::controller(CustomerAccountsController::class)->group(function () {
                Route::get('customer-accounts', 'index');
                Route::get('customer-accounts/{id}', 'show')->whereNumber('id');
            });
            Route::controller(DealController::class)->group(function () {
                Route::get('deals', 'index');
                Route::post('deals', 'store');
                Route::get('deals/clients', 'clients');
                Route::get('deals/{id}', 'show')->whereNumber('id');
                Route::get('deals/{id}/pdf', 'pdf')->whereNumber('id');
                Route::post('deals/{id}/payments', 'pay')->whereNumber('id')->middleware('throttle:media-upload');
                Route::post('deals/{id}/void', 'void')->whereNumber('id');
            });
        });

        // Accounts (docs/phase-9-accounts.md): the chart of accounts, the journal behind every figure, and the two
        // reports. Reading needs accounts.view; adding an account or posting an entry needs its own permission.
        Route::middleware('permission:accounts.view,staff')->group(function () {
            Route::controller(AccountController::class)->group(function () {
                Route::get('accounts', 'index');
                Route::post('accounts', 'store')->middleware('permission:accounts.manage,staff');
                Route::put('accounts/{id}', 'update')->whereNumber('id')->middleware('permission:accounts.manage,staff');
                Route::delete('accounts/{id}', 'destroy')->whereNumber('id')->middleware('permission:accounts.manage,staff');
            });
            Route::controller(JournalController::class)->group(function () {
                Route::get('journal-entries', 'index');
                Route::post('journal-entries', 'store')->middleware('permission:journal.post,staff');
                Route::post('journal-entries/{id}/reverse', 'reverse')->whereNumber('id')->middleware('permission:journal.post,staff');
            });
            Route::controller(AccountingReportController::class)->group(function () {
                Route::get('reports/account-transactions', 'accountTransactions');
                Route::get('reports/general-ledger', 'generalLedger');
            });
        });

        // Bookings, invoices and payments. Per-action permissions are checked in the controller.
        Route::middleware('permission:bookings.view_all|bookings.view_own,staff')->controller(BookingController::class)->group(function () {
            Route::get('bookings', 'index');
            Route::get('bookings/options', [StaffBookingController::class, 'options']);
            Route::post('bookings', [StaffBookingController::class, 'store']);
            Route::get('bookings/{id}', 'show')->whereNumber('id');
            // E-tickets per traveller (docs/phase-6-customer-portal.md §8).
            Route::post('bookings/{id}/tickets', [BookingTicketController::class, 'store'])->whereNumber('id');
            Route::post('booking-tickets/{ticketId}/void', [BookingTicketController::class, 'void'])->whereNumber('ticketId');
            Route::get('booking-tickets/{ticketId}/file', [BookingTicketController::class, 'file'])->whereNumber('ticketId');
            // Traveller documents from the portal (docs/phase-6-customer-portal.md §3.3).
            Route::get('document-reviews', [DocumentReviewController::class, 'index']);
            Route::get('traveller-documents/{id}/file', [DocumentReviewController::class, 'file'])->whereNumber('id');
            Route::post('traveller-documents/{id}/review', [DocumentReviewController::class, 'review'])->whereNumber('id');
            Route::put('booking-travellers/{travellerId}/documents/{kind}', [DocumentReviewController::class, 'setStatus'])->whereNumber('travellerId')->whereIn('kind', TravellerDocument::ISSUED);
            Route::put('booking-travellers/{travellerId}', 'updateTraveller')->whereNumber('travellerId');
            Route::delete('bookings/{id}', 'destroy')->whereNumber('id');
            Route::put('bookings/{id}/quote', 'updateQuote')->whereNumber('id');
            Route::post('bookings/{id}/invoice', 'issueInvoice')->whereNumber('id');
            Route::get('bookings/{id}/invoice/print', 'invoiceHtml')->whereNumber('id');
            Route::get('bookings/{id}/invoice/pdf', 'invoicePdf')->whereNumber('id');
            Route::post('bookings/{id}/payments', 'recordPayment')->whereNumber('id');
            Route::post('bookings/{id}/claim', 'claim')->whereNumber('id');
            Route::post('bookings/{id}/assign', 'assign')->whereNumber('id');
            Route::post('bookings/{id}/{action}', 'transition')->whereNumber('id')->whereIn('action', ['confirm', 'complete', 'cancel']);
            Route::post('invoices/{invoiceId}/void', 'voidInvoice')->whereNumber('invoiceId');
            Route::post('transactions/{transactionId}/reverse', 'reversePayment')->whereNumber('transactionId');
        });

        Route::middleware('permission:pricing.manage,staff')->controller(PricingController::class)->group(function () {
            Route::get('pricing', 'show');
            Route::put('pricing', 'update');
            Route::get('addons', 'addons');
            Route::post('addons', 'storeAddon');
            Route::put('addons/{id}', 'updateAddon')->whereNumber('id');
        });

        Route::middleware('permission:cms.manage,staff')->group(function () {
            Route::controller(BlogPostController::class)->group(function () {
                Route::get('posts', 'index');
                Route::post('posts', 'store');
                Route::get('posts/{id}', 'show')->whereNumber('id');
                Route::put('posts/{id}', 'update')->whereNumber('id');
                Route::delete('posts/{id}', 'destroy')->whereNumber('id');
                Route::post('posts/{id}/publish', 'publish')->whereNumber('id');
                Route::post('posts/{id}/unpublish', 'unpublish')->whereNumber('id');
            });

            Route::controller(BlogCategoryController::class)->group(function () {
                Route::get('blog-categories', 'index');
                Route::post('blog-categories', 'store');
                Route::put('blog-categories/{id}', 'update')->whereNumber('id');
                Route::delete('blog-categories/{id}', 'destroy')->whereNumber('id');
            });

            foreach (['team' => TeamMemberController::class, 'reviews' => ReviewController::class, 'gallery' => GalleryItemController::class, 'visas' => VisaServiceController::class] as $path => $controller) {
                Route::controller($controller)->group(function () use ($path) {
                    Route::get($path, 'index');
                    Route::post($path, 'store');
                    Route::put("{$path}/order", 'reorder');
                    Route::get("{$path}/{id}", 'show')->whereNumber('id');
                    Route::put("{$path}/{id}", 'update')->whereNumber('id');
                    Route::delete("{$path}/{id}", 'destroy')->whereNumber('id');
                    Route::post("{$path}/{id}/publish", 'publish')->whereNumber('id');
                    Route::post("{$path}/{id}/unpublish", 'unpublish')->whereNumber('id');
                });
            }

            Route::controller(SiteSettingController::class)->group(function () {
                Route::get('settings', 'index');
                Route::put('settings/{key}', 'update');
            });
        });
    });
});
