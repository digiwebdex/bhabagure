<?php

use App\Http\Controllers\Api\V1\Admin\AirInquiryController;
use App\Http\Controllers\Api\V1\Admin\AssignableStaffController;
use App\Http\Controllers\Api\V1\Admin\BlogCategoryController;
use App\Http\Controllers\Api\V1\Admin\BlogPostController;
use App\Http\Controllers\Api\V1\Admin\BookingController;
use App\Http\Controllers\Api\V1\Admin\CatalogueController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DealController;
use App\Http\Controllers\Api\V1\Admin\DepartureController;
use App\Http\Controllers\Api\V1\Admin\DocumentReviewController;
use App\Http\Controllers\Api\V1\Admin\GalleryItemController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\NavCountController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\Admin\PackageImageController;
use App\Http\Controllers\Api\V1\Admin\PaymentsController;
use App\Http\Controllers\Api\V1\Admin\PricingController;
use App\Http\Controllers\Api\V1\Admin\ProfileWhatsAppController;
use App\Http\Controllers\Api\V1\Admin\QuotationController;
use App\Http\Controllers\Api\V1\Admin\ReferencePresetController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\SearchController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingController;
use App\Http\Controllers\Api\V1\Admin\StaffBookingController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController;
use App\Http\Controllers\Api\V1\Admin\TeamMemberController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Payments\FakeGatewayController;
use App\Http\Controllers\Api\V1\Payments\SslCommerzCallbackController;
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
        Route::get('payments', [PortalPaymentController::class, 'index']);
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
            Route::get('pricing', 'pricing');
            Route::get('settings', 'settings');
        });

        Route::controller(PublicFormController::class)->middleware('throttle:public-forms')->group(function () {
            Route::post('inquiries', 'inquiry');
            Route::post('air-quotes', 'airQuote');
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

        // Sidebar badges, derived from the same scoped queries as their lists (docs/phase-5-admin-core.md §3.1).
        Route::get('nav-counts', NavCountController::class);
        // The Dashboard (§4.2): every widget computed now, each behind its permission.
        Route::get('dashboard', DashboardController::class);
        Route::get('assignable-staff', AssignableStaffController::class);
        // Header search: top five of each kind, through the same visibility scopes (§4.1).
        Route::get('search', SearchController::class)->middleware('throttle:public-read');

        // Customers & leads (§4.4). Per-action permissions and ownership are checked in the controller.
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

        // The Air ticketing queue: website air-ticket enquiries (§4.7). Per-action permissions are checked in the controller.
        Route::middleware('permission:air_inquiries.view,staff')->controller(AirInquiryController::class)->group(function () {
            Route::get('air-inquiries', 'index');
            Route::get('air-inquiries/{id}', 'show')->whereNumber('id');
            Route::post('air-inquiries/{id}/claim', 'claim')->whereNumber('id');
            Route::post('air-inquiries/{id}/assign', 'assign')->whereNumber('id');
            Route::post('air-inquiries/{id}/quoted', 'markQuoted')->whereNumber('id');
            Route::delete('air-inquiries/{id}/quoted', 'undoQuoted')->whereNumber('id');
        });

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
                Route::get('payment-attempts/review', 'reviewIndex');
                Route::post('payment-attempts/{id}/review', 'markReviewed')->whereNumber('id');
            });
            Route::controller(ReferencePresetController::class)->group(function () {
                Route::get('reference-presets', 'index');
                Route::post('reference-presets', 'store');
                Route::delete('reference-presets/{id}', 'destroy')->whereNumber('id');
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

        // Bookings, invoices and payments. Per-action permissions are checked in the controller.
        Route::middleware('permission:bookings.view_all|bookings.view_own,staff')->controller(BookingController::class)->group(function () {
            Route::get('bookings', 'index');
            Route::get('bookings/options', [StaffBookingController::class, 'options']);
            Route::post('bookings', [StaffBookingController::class, 'store']);
            Route::get('bookings/{id}', 'show')->whereNumber('id');
            // Traveller documents from the portal (docs/phase-6-customer-portal.md §3.3).
            Route::get('document-reviews', [DocumentReviewController::class, 'index']);
            Route::get('traveller-documents/{id}/file', [DocumentReviewController::class, 'file'])->whereNumber('id');
            Route::post('traveller-documents/{id}/review', [DocumentReviewController::class, 'review'])->whereNumber('id');
            Route::put('booking-travellers/{travellerId}/documents/{kind}', [DocumentReviewController::class, 'setStatus'])->whereNumber('travellerId')->whereIn('kind', TravellerDocument::ISSUED);
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

            foreach (['team' => TeamMemberController::class, 'reviews' => ReviewController::class, 'gallery' => GalleryItemController::class] as $path => $controller) {
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
