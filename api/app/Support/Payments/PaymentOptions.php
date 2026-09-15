<?php

namespace App\Support\Payments;

use App\Models\SiteSetting;
use App\Support\Numerals;
use App\Support\Pricing\PricingService;

/**
 * How a customer pays by hand (docs/phase-8-visa-quotes-pricing-downloads.md §4.F): NPSB bank transfer, the SSLCommerz
 * payment link while the built-in checkout is off, and bKash with its charge added. The details are the `payment` site
 * setting (Admin → Site settings → Payment); every screen, PDF and message takes its figures from here.
 */
final class PaymentOptions
{
    public const SETTING = 'payment';

    /**
     * The saved details, each method null until it is filled in.
     *
     * @return array{bank: array{bankName: string, accountName: string, accountNumber: string, branch: string, routingNumber: string, transferType: string}|null, link: string|null, bkash: array{number: string, chargePercent: float}|null}
     */
    public static function settings(): array
    {
        $value = SiteSetting::get(self::SETTING, []);
        $bank = is_array($value['bank'] ?? null) && filled($value['bank']['accountNumber'] ?? null) ? $value['bank'] : null;
        $bkash = is_array($value['bkash'] ?? null) && filled($value['bkash']['number'] ?? null) ? $value['bkash'] : null;

        return [
            'bank' => $bank ? [
                'bankName' => (string) ($bank['bankName'] ?? ''), 'accountName' => (string) ($bank['accountName'] ?? ''),
                'accountNumber' => (string) $bank['accountNumber'], 'branch' => (string) ($bank['branch'] ?? ''),
                'routingNumber' => (string) ($bank['routingNumber'] ?? ''), 'transferType' => (string) ($bank['transferType'] ?? '') ?: 'NPSB',
            ] : null,
            'link' => filled($value['link'] ?? null) ? (string) $value['link'] : null,
            'bkash' => $bkash ? ['number' => (string) $bkash['number'], 'chargePercent' => (float) ($bkash['chargePercent'] ?? 0)] : null,
        ];
    }

    /**
     * Whether the built-in SSLCommerz checkout takes real payments here: live mode with the store's credentials in
     * production; outside production the fake gateway, or the sandbox with credentials. Until then the payment link
     * stands in for it.
     */
    public static function checkoutAvailable(): bool
    {
        $config = config('bhabaghure.sslcommerz');
        $credentials = filled($config['store_id'] ?? null) && filled($config['store_password'] ?? null);

        return match ($config['mode'] ?? 'off') {
            'live' => app()->isProduction() && $credentials,
            'sandbox' => ! app()->isProduction() && $credentials,
            'fake' => ! app()->isProduction(),
            default => false,
        };
    }

    /**
     * Each method with the exact amount to send for `$amount` taka; null when nothing is due or nothing is set. The link
     * is left out while the built-in checkout is available.
     *
     * @return array{amount: int|float, bank: array<string, string>|null, link: string|null, bkash: array{number: string, chargePercent: float, charge: int, total: int|float}|null}|null
     */
    public static function forAmount(int|float $amount, ?bool $checkout = null): ?array
    {
        $settings = self::settings();
        $link = ($checkout ?? self::checkoutAvailable()) ? null : $settings['link'];
        if ($amount <= 0 || ($settings['bank'] === null && $link === null && $settings['bkash'] === null)) {
            return null;
        }
        $bkash = $settings['bkash'] ? PricingService::onlinePayment($amount, $settings['bkash']['chargePercent']) : null;

        return [
            'amount' => $amount,
            'bank' => $settings['bank'],
            'link' => $link,
            'bkash' => $settings['bkash'] ? $settings['bkash'] + ['charge' => $bkash['charge'], 'total' => $bkash['total']] : null,
        ];
    }

    /**
     * The same as plain text lines, for WhatsApp, email and PDFs: "Bank (NPSB): …", "Card, mobile banking or EMI: <link>",
     * "bKash (send money) 01…: ৳ 77,495 including the 1.3% bKash charge". Empty when there is nothing to say.
     *
     * @return list<string>
     */
    public static function lines(int|float $amount, string $locale, ?bool $checkout = null): array
    {
        $options = self::forAmount($amount, $checkout);
        if ($options === null) {
            return [];
        }
        $en = $locale === 'en';
        $money = fn (int|float $value) => Numerals::bdt($value, $locale);
        $lines = [];
        if ($bank = $options['bank']) {
            $lines[] = $en
                ? "Bank ({$bank['transferType']}): {$bank['bankName']}, {$bank['accountName']}, A/C {$bank['accountNumber']}, {$bank['branch']} branch, routing {$bank['routingNumber']}: {$money($amount)}"
                : "ব্যাংক ({$bank['transferType']}): {$bank['bankName']}, {$bank['accountName']}, হিসাব নম্বর {$bank['accountNumber']}, {$bank['branch']} শাখা, রাউটিং {$bank['routingNumber']}: {$money($amount)}";
        }
        if ($options['link']) {
            $lines[] = ($en ? 'Card, mobile banking or EMI: ' : 'কার্ড, মোবাইল ব্যাংকিং বা EMI: ').$options['link'];
        }
        if ($bkash = $options['bkash']) {
            $number = self::localNumber($bkash['number']);
            $percent = Numerals::percent($bkash['chargePercent'], $locale);
            $lines[] = $en
                ? "bKash (send money) {$number}: {$money($bkash['total'])}, including the {$percent} bKash charge"
                : "বিকাশ (সেন্ড মানি) {$number}: {$money($bkash['total'])}, {$percent} বিকাশ চার্জসহ";
        }

        return $lines;
    }

    /** Changes whenever what a printed document says about paying would change: part of a stored PDF's key. */
    public static function fingerprint(): string
    {
        return sha1(json_encode([self::settings(), self::checkoutAvailable()]) ?: '');
    }

    /** bKash is sent to the local form of the number: 01XXXXXXXXX. */
    public static function localNumber(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $phone;
    }
}
