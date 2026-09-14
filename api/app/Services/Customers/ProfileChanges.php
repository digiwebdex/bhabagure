<?php

namespace App\Services\Customers;

use App\Mail\CustomerEmailCodeMail;
use App\Models\Customer;
use App\Models\CustomerEmailChange;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Changing the identity a portal account signs in with (docs/phase-6-customer-portal.md §3.1, §3.6). A new email is
 * confirmed with a code sent to it; a new phone with a code sent to it by LoginCodes. Neither may take another
 * customer's number or address — that answer comes only after the code, so it reveals nothing to someone who doesn't
 * hold the address.
 */
final class ProfileChanges
{
    public const EMAIL_MINUTES = 30;

    private const EMAIL_PER_HOUR = 5;

    public function __construct(private readonly LoginCodes $codes, private readonly AuditLogger $audit) {}

    /** @return 'sent'|'throttled' */
    public function requestEmail(Customer $customer, string $email, string $locale): string
    {
        $recent = CustomerEmailChange::query()->where('customer_id', $customer->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= self::EMAIL_PER_HOUR) {
            return 'throttled';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($customer, $email, $code) {
            CustomerEmailChange::query()->where('customer_id', $customer->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
            CustomerEmailChange::query()->create([
                'customer_id' => $customer->id, 'email' => $email, 'code_hash' => self::hash($customer->id, $email, $code),
                'expires_at' => now()->addMinutes(self::EMAIL_MINUTES),
            ]);
        });
        Mail::to($email)->send(new CustomerEmailCodeMail($code, $locale));

        return 'sent';
    }

    /** @return 'changed'|'invalid'|'unavailable' */
    public function confirmEmail(Customer $customer, string $code): string
    {
        return DB::transaction(function () use ($customer, $code) {
            $row = CustomerEmailChange::query()->where('customer_id', $customer->id)->whereNull('consumed_at')->where('expires_at', '>', now())
                ->latest('id')->lockForUpdate()->first();
            if ($row === null || $row->attempts >= LoginCodes::MAX_ATTEMPTS) {
                return 'invalid';
            }
            if (! hash_equals($row->code_hash, self::hash($customer->id, $row->email, $code))) {
                $row->increment('attempts');

                return 'invalid';
            }
            $row->forceFill(['consumed_at' => now()])->save();

            if (Customer::query()->where('email', $row->email)->whereKeyNot($customer->id)->exists()) {
                return 'unavailable';
            }
            try {
                $customer->forceFill(['email' => $row->email, 'email_verified_at' => now()])->save();
            } catch (UniqueConstraintViolationException) {
                return 'unavailable';
            }
            $this->audit->record('customer.email_changed', $customer, $customer);

            return 'changed';
        });
    }

    /** @return 'changed'|'invalid'|'unavailable' */
    public function changePhone(Customer $customer, string $phone, string $code): string
    {
        $match = $this->codes->match($phone, $code, LoginCodes::CHANGE_PHONE);
        if ($match === null) {
            return 'invalid';
        }

        return DB::transaction(function () use ($customer, $phone, $match) {
            $this->codes->consume($match);
            if (Customer::query()->where('phone', $phone)->whereKeyNot($customer->id)->exists()) {
                return 'unavailable';
            }
            try {
                $customer->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
            } catch (UniqueConstraintViolationException) {
                return 'unavailable';
            }
            $this->audit->record('customer.phone_changed', $customer, $customer);

            return 'changed';
        });
    }

    private static function hash(int $customerId, string $email, string $code): string
    {
        return hash_hmac('sha256', "{$customerId}|".mb_strtolower($email)."|{$code}", (string) config('app.key'));
    }
}
