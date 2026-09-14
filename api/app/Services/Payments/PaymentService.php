<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentAttemptStatus;
use App\Events\PaymentRecorded;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use App\Models\SeatHold;
use App\Services\AuditLogger;
use App\Services\Booking\BookingStateMachine;
use App\Services\Booking\DepartureSeats;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\SslCommerz\GatewayUnavailable;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Online payments through SSLCommerz (docs/phase-3-booking.md §4).
 *
 * Nothing posted by the browser or the IPN is trusted: every outcome is confirmed with SSLCommerz's Validation or
 * transaction-query API, and the amount credited is the attempt's own snapshot. settle() is idempotent — the attempt
 * row is locked and a settled attempt returns at once; the cash book's unique (method, external_ref) and a paid amount
 * recomputed from the ledger are the further guards. A failed or abandoned payment leaves the booking an unpaid inquiry.
 */
final class PaymentService
{
    /** Where the customer's chosen method sends them on the SSLCommerz page (multi_card_name). */
    public const METHODS = [
        'bkash' => 'bkash',
        'nagad' => 'mobilebank',
        'card' => 'visacard,mastercard,amexcard',
        'bank' => 'internetbank',
    ];

    public function __construct(
        private readonly SslCommerzGateway $gateway,
        private readonly LedgerService $ledger,
        private readonly InvoiceIssuer $invoices,
        private readonly BookingStateMachine $bookings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What paying the booking's balance online costs right now: the balance plus the configured online payment charge.
     * The website shows exactly this, and the gateway is asked for exactly `total`.
     *
     * @return array{amount: int|float, chargePercent: int|float, charge: int, total: int|float}
     */
    public static function quote(Booking $booking): array
    {
        return PricingService::onlinePayment(Money::toNumber($booking->due_amount) ?? 0, PricingConfig::current()->onlinePaymentChargePercent);
    }

    /**
     * Opens an SSLCommerz session for the booking's full balance (decision 2: the full amount online), plus the online
     * payment charge if one is configured. $expectedTotal is what the customer was shown; if it no longer matches,
     * nothing is started (the price shown is the price paid).
     *
     * @throws PaymentNotAllowed|PaymentAmountChanged|SeatsUnavailable|GatewayUnavailable
     */
    public function start(Booking $booking, string $method, int|float|null $expectedTotal = null): PaymentAttempt
    {
        $attempt = DB::transaction(function () use ($booking, $method, $expectedTotal) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($booking->status, [BookingStatus::Inquiry, BookingStatus::Confirmed], true) || (float) $booking->due_amount <= 0) {
                throw new PaymentNotAllowed($booking);
            }

            $online = self::quote($booking);
            if ($expectedTotal !== null && LedgerService::paisa($expectedTotal) !== LedgerService::paisa($online['total'])) {
                throw new PaymentAmountChanged($online);
            }

            $expires = now()->addMinutes((int) config('bhabaghure.booking.hold_minutes'));
            $this->holdSeats($booking, $expires);

            // One open attempt at a time: an older unfinished one is superseded (reconciliation still checks it).
            PaymentAttempt::query()->where('booking_id', $booking->id)->whereIn('status', ['initiated', 'redirected'])
                ->update(['expires_at' => now()]);

            return PaymentAttempt::query()->create([
                'booking_id' => $booking->id,
                'gateway' => 'sslcommerz',
                'tran_id' => 'BHT'.Str::upper(Str::random(21)),
                'amount' => $online['amount'],
                'online_charge' => $online['charge'],
                'currency' => 'BDT',
                'method_hint' => $method,
                'status' => PaymentAttemptStatus::Initiated,
                'expires_at' => $expires,
            ]);
        });

        $booking->loadMissing(['customer', 'travellers']);
        $lead = $booking->travellers->firstWhere('is_lead', true);
        $callback = fn (string $outcome) => url("/api/v1/payments/sslcommerz/{$outcome}");

        try {
            $session = $this->gateway->createSession([
                'total_amount' => LedgerService::amount($attempt->expectedPaisa()),
                'currency' => 'BDT',
                'tran_id' => $attempt->tran_id,
                'success_url' => $callback('success'),
                'fail_url' => $callback('fail'),
                'cancel_url' => $callback('cancel'),
                'ipn_url' => $callback('ipn'),
                'multi_card_name' => self::METHODS[$method] ?? '',
                'cus_name' => Str::limit($lead?->full_name ?? $booking->customer->name, 50, ''),
                'cus_email' => $lead?->email ?: ($booking->customer->email ?: config('bhabaghure.sslcommerz.fallback_email')),
                'cus_add1' => Str::limit($booking->customer->address ?: 'Dhaka', 50, ''),
                'cus_city' => 'Dhaka',
                'cus_country' => 'Bangladesh',
                'cus_phone' => $lead?->phone ?: $booking->customer->phone,
                'shipping_method' => 'NO',
                'num_of_item' => 1,
                'product_name' => Str::limit($booking->package_title_en, 50, ''),
                'product_category' => 'Tour package',
                'product_profile' => 'general',
                'value_a' => $booking->reference,
            ]);
        } catch (GatewayUnavailable $e) {
            $this->close($attempt, PaymentAttemptStatus::Failed, 'gateway_unreachable');
            throw $e;
        }

        if (($session['status'] ?? null) !== 'SUCCESS' || empty($session['GatewayPageURL'])) {
            $this->close($attempt, PaymentAttemptStatus::Failed, Str::limit((string) ($session['failedreason'] ?? 'session_refused'), 250, ''));
            throw new GatewayUnavailable('SSLCommerz refused the session: '.($session['failedreason'] ?? 'no reason given'));
        }

        $attempt->update([
            'status' => PaymentAttemptStatus::Redirected,
            'session_key' => $session['sessionkey'] ?? null,
            'gateway_url' => $session['GatewayPageURL'],
        ]);

        return $attempt;
    }

    /**
     * Success redirect, IPN and reconciliation all end here. Confirms val_id with SSLCommerz, then credits once.
     * Returns the attempt as it ended up; null when the tran_id isn't ours.
     */
    public function settle(string $tranId, string $valId, string $via): ?PaymentAttempt
    {
        $known = PaymentAttempt::query()->where('tran_id', $tranId)->first();
        if (! $known) {
            Log::warning('SSLCommerz callback for an unknown tran_id', ['tran_id' => $tranId, 'via' => $via]);

            return null;
        }
        if ($known->status === PaymentAttemptStatus::Settled) {
            return $known;
        }

        $validation = $this->gateway->validate($valId);

        return DB::transaction(function () use ($known, $valId, $validation, $via) {
            $attempt = PaymentAttempt::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();
            if (! $attempt->status->canSettle()) {
                return $attempt;
            }
            $booking = Booking::query()->whereKey($attempt->booking_id)->lockForUpdate()->firstOrFail();

            $status = $validation['status'] ?? null;
            $gatewayAmount = (float) ($validation['currency_amount'] ?? $validation['amount'] ?? 0);
            $currency = $validation['currency_type'] ?? $validation['currency'] ?? null;
            $record = [
                'val_id' => $valId,
                'bank_tran_id' => $validation['bank_tran_id'] ?? null,
                'card_type' => isset($validation['card_type']) ? Str::limit((string) $validation['card_type'], 60, '') : null,
                'risk_level' => isset($validation['risk_level']) ? (int) $validation['risk_level'] : null,
                'gateway_amount' => $gatewayAmount ?: null,
                'store_amount' => isset($validation['store_amount']) ? (float) $validation['store_amount'] : null,
                'gateway_response' => self::withoutSecrets($validation),
            ];

            if (! in_array($status, ['VALID', 'VALIDATED'], true) || ($validation['tran_id'] ?? null) !== $attempt->tran_id) {
                // Not (or not yet) a payment. A failed validation never closes an open attempt on its own.
                $attempt->update($record + ['failure_reason' => 'validation_'.strtolower((string) $status)]);

                return $attempt;
            }

            // Exactly what the customer was shown and the gateway was asked for.
            $expectedPaisa = $attempt->expectedPaisa();
            $paidPaisa = LedgerService::paisa($gatewayAmount);
            $review = match (true) {
                $currency !== 'BDT' => 'currency_mismatch',
                $paidPaisa < $expectedPaisa => 'amount_below_expected',
                (int) ($validation['risk_level'] ?? 0) === 1 => 'high_risk',
                $booking->status === BookingStatus::Cancelled => 'booking_cancelled',
                default => null,
            };
            if ($review !== null) {
                $attempt->update($record + ['status' => PaymentAttemptStatus::NeedsReview, 'failure_reason' => $review, 'closed_at' => now()]);
                $this->audit->record('payment.needs_review', null, $booking, ['tran_id' => $attempt->tran_id, 'reason' => $review, 'via' => $via]);

                return $attempt;
            }

            // The gateway's fee comes out of the settlement (store_amount): a business cost, never the customer's.
            $storePaisa = $record['store_amount'] === null ? $expectedPaisa : LedgerService::paisa($record['store_amount']);
            $gatewayFeePaisa = max(0, $expectedPaisa - $storePaisa);
            // Collected above what we showed the customer: the store's gateway settings are wrong. The payment still
            // counts (the money was taken); staff are alerted to refund the difference and fix the settings.
            $surchargePaisa = max(0, $paidPaisa - $expectedPaisa);

            $this->invoices->issueForBooking($booking);
            $payment = $this->ledger->recordPayment(
                $booking, $attempt->amount, 'sslcommerz', "SSLCommerz {$attempt->tran_id}", $attempt->tran_id,
                onlineCharge: $attempt->online_charge, gatewayFee: LedgerService::amount($gatewayFeePaisa),
                referenceLabel: $record['bank_tran_id'], allowOverpayment: true,
            );
            $attempt->update($record + [
                'status' => PaymentAttemptStatus::Settled,
                'gateway_fee' => LedgerService::amount($gatewayFeePaisa),
                'gateway_surcharge' => LedgerService::amount($surchargePaisa),
                'failure_reason' => $surchargePaisa > 0 ? 'gateway_surcharge' : null,
                'settled_at' => now(),
                'closed_at' => now(),
            ]);

            $booking->refresh();
            $confirmsBooking = $booking->status === BookingStatus::Inquiry && (float) $booking->due_amount <= 0;
            if ($confirmsBooking) {
                $this->bookings->confirm($booking);
            }
            // One message for one payment: when it confirms the booking, the confirmation message covers it.
            PaymentRecorded::dispatch($booking, $payment, $confirmsBooking);
            $this->audit->record('payment.settled', null, $booking, [
                'tran_id' => $attempt->tran_id, 'amount' => $attempt->amount, 'online_charge' => $attempt->online_charge,
                'gateway_fee' => LedgerService::amount($gatewayFeePaisa), 'via' => $via,
            ]);
            if ($surchargePaisa > 0) {
                $this->audit->record('payment.gateway_surcharge', null, $booking, [
                    'tran_id' => $attempt->tran_id, 'shown' => LedgerService::amount($expectedPaisa), 'collected' => LedgerService::amount($paidPaisa),
                ]);
                Log::error('SSLCommerz collected more than the customer was shown', ['tran_id' => $attempt->tran_id, 'shown' => $expectedPaisa / 100, 'collected' => $paidPaisa / 100]);
            }

            return $attempt;
        });
    }

    /**
     * Fail and cancel callbacks, and attempts past their window. SSLCommerz is asked first: if the money was in fact
     * taken, the attempt settles. Otherwise it closes and the seats go back; the booking stays an unpaid inquiry.
     */
    public function closeUnpaid(PaymentAttempt $attempt, PaymentAttemptStatus $as, string $reason): PaymentAttempt
    {
        if (! $attempt->status->isOpen()) {
            return $attempt;
        }

        $found = $this->gateway->queryByTransactionId($attempt->tran_id);
        foreach ((array) ($found['element'] ?? []) as $element) {
            if (in_array($element['status'] ?? null, ['VALID', 'VALIDATED'], true) && ! empty($element['val_id'])) {
                $settled = $this->settle($attempt->tran_id, (string) $element['val_id'], 'query');
                if ($settled && ! $settled->status->isOpen()) {
                    return $settled;
                }
            }
        }

        return $this->close($attempt, $as, $reason);
    }

    /** Attempts nobody came back from. Scheduled every 10 minutes (routes/console.php). */
    public function reconcile(): int
    {
        $count = 0;
        PaymentAttempt::query()->whereIn('status', ['initiated', 'redirected'])->where('expires_at', '<', now())
            ->orderBy('id')->limit(100)->get()
            ->each(function (PaymentAttempt $attempt) use (&$count) {
                try {
                    $this->closeUnpaid($attempt, PaymentAttemptStatus::Expired, 'no_callback');
                    $count++;
                } catch (GatewayUnavailable $e) {
                    Log::warning('SSLCommerz reconciliation could not reach the gateway', ['tran_id' => $attempt->tran_id, 'error' => $e->getMessage()]);
                }
            });

        return $count;
    }

    private function close(PaymentAttempt $attempt, PaymentAttemptStatus $as, string $reason): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $as, $reason) {
            $attempt = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (! $attempt->status->isOpen()) {
                return $attempt;
            }
            $attempt->update(['status' => $as, 'failure_reason' => $reason, 'closed_at' => now()]);

            $stillPaying = PaymentAttempt::query()->where('booking_id', $attempt->booking_id)->whereIn('status', ['initiated', 'redirected'])
                ->where('expires_at', '>', now())->exists();
            if (! $stillPaying) {
                SeatHold::query()->where('booking_id', $attempt->booking_id)->whereNull('released_at')->whereNull('converted_at')
                    ->update(['released_at' => now()]);
            }

            return $attempt;
        });
    }

    /** Holds the booking's seats until the payment window closes, re-checking availability if an old hold lapsed. */
    private function holdSeats(Booking $booking, \DateTimeInterface $until): void
    {
        $departure = $booking->departure()->lockForUpdate()->first();
        if (! $departure || $departure->seats_total === null || $booking->status !== BookingStatus::Inquiry) {
            return;
        }

        $active = SeatHold::query()->active()->where('booking_id', $booking->id)->first();
        if ($active) {
            $active->update(['expires_at' => $until]);

            return;
        }
        if (DepartureSeats::available($departure) < $booking->pax_count) {
            throw new SeatsUnavailable(DepartureSeats::available($departure));
        }
        SeatHold::query()->create(['departure_id' => $departure->id, 'booking_id' => $booking->id, 'seats' => $booking->pax_count, 'expires_at' => $until]);
    }

    /** @param array<string, mixed> $response */
    private static function withoutSecrets(array $response): array
    {
        // Store credentials, signatures and card tokens never go into the database (the card number is already masked).
        return array_diff_key($response, array_flip(['store_passwd', 'store_id', 'verify_sign', 'verify_sign_sha2', 'verify_key', 'token_key', 'card_ref_id']));
    }

    public static function assertMethod(string $method): void
    {
        if (! array_key_exists($method, self::METHODS)) {
            throw new LogicException("Unknown payment method {$method}");
        }
    }
}
