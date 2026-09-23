'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';

import { onlinePayment, type Quote } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { createBooking, markJustBooked, recallBookingToken, rememberBookingToken, sendBookingCode, startPayment, type ApiFailure } from '@/lib/booking-api';
import type { PackageView } from '@/lib/content/views';
import { useRouter } from '@/i18n/navigation';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeBdMobile, normalizeDigits, parseDayMonthYear } from '@/lib/validators';
import { useBooking, type CreatedBooking, type PaymentMethod } from '@/state/booking';

import { applyCoupon, removeCoupon } from './coupon';
import { PhoneCodeBox, type CodeNote } from './PhoneCodeBox';
import { onlineChargeLine, PriceBreakdown, quoteLines } from './ReviewStep';

const METHODS: PaymentMethod[] = ['bkash', 'nagad', 'card', 'bank'];

/**
 * Pay the full amount through SSLCommerz (decision 2); while that checkout is off (Phase 8 §4.F), save the booking and
 * open its page, which shows how to pay by bank transfer, the payment link or bKash. The booking is created first — as an unpaid inquiry — so a
 * failed or abandoned payment never loses it; then the API opens an SSLCommerz session and we go there. The total
 * shown is the same quote as every earlier step; if the server's price differs, nothing is charged and the new total
 * is shown instead.
 */
export function PaymentStep({ pkg, quote, hotelCategory }: { pkg: PackageView; quote: Quote; hotelCategory: string | null }) {
  const t = useTranslations('booking');
  const locale = useLocale() as 'bn' | 'en';
  const { addons, pricing, settings } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  // Kept in the booking store, not here: if this step is drawn again, it must not book a second time.
  const created = booking.created;
  // The same amount the review step showed; the API refuses to start a payment for any other.
  const online = onlinePayment(quote.total, pricing.onlinePaymentChargePercent);
  const checkout = pricing.onlineCheckout === true;
  const prefix = locale === 'en' ? '/en' : '';
  // The coupon priced into `quote` (docs/coupons.md): only its code goes with the booking. While it is being checked
  // again after a change, nothing is booked.
  const couponCode = quote.discount > 0 ? (booking.coupon?.code ?? null) : null;
  const couponChecking = booking.couponCheck.status === 'checking';

  // docs/booking-phone-verification.md: while the check is on, a code goes to the lead's mobile first and the booking is
  // saved only with it. The API asks too (for a page cached before the switch went on), and this step then follows it.
  const [verify, setVerify] = useState(pricing.verifyPhone === true);
  const leadPhone = normalizeBdMobile(booking.travellers[0]?.phone ?? '');
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [codeNote, setCodeNote] = useState<CodeNote>(null);
  const [waitSeconds, setWaitSeconds] = useState(0);
  const [sending, setSending] = useState(false);
  const typedCode = normalizeDigits(code).replace(/\s/g, '');

  // The wait before another code: ticks only while it runs.
  useEffect(() => {
    if (waitSeconds <= 0) return;
    const timer = setTimeout(() => setWaitSeconds((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [waitSeconds]);

  /** Sends the code. 'sent' (or one went a moment ago), 'off' (nothing asks for one any more), 'failed' (the reason shows). */
  const requestCode = async (): Promise<'sent' | 'off' | 'failed'> => {
    if (!leadPhone) return 'failed';
    setSending(true);
    setCodeNote(null);
    setFailure(null);
    const result = await sendBookingCode(leadPhone, locale);
    setSending(false);
    if (result.ok) {
      setSentTo(leadPhone);
      setCode('');
      setWaitSeconds(result.data.retry_after);
      return 'sent';
    }
    if (result.reason === 'throttled') {
      // A code went to this number a moment ago: the customer types that one.
      setSentTo(leadPhone);
      setWaitSeconds(result.retryAfter);
      setCodeNote({ tone: 'info', text: t('verify.throttled', { secondsText: f.number(result.retryAfter) }) });
      return 'sent';
    }
    if (result.reason === 'not_found') {
      // Switched off since this page was made: there is nothing to check.
      setVerify(false);
      return 'off';
    }
    setFailure(result);
    return 'failed';
  };

  // Made: the form closes and the booking's own page opens with the congratulations and how to pay.
  const openBooking = (reference: string, token: string) => {
    markJustBooked(reference);
    booking.finish();
    router.push(`/booking/${reference}#t=${token}`);
  };

  const pay = async () => {
    if (busy || couponChecking || sending) return;
    // First "Confirm": the code goes out and its box opens. Then: the booking, with the code.
    let checking = verify && !created;
    if (checking && sentTo !== leadPhone) {
      if ((await requestCode()) !== 'off') return;
      checking = false;
    }
    if (checking && !/^\d{6}$/.test(typedCode)) {
      setCodeNote({ tone: 'error', text: t('verify.enterCode') });
      return;
    }
    setBusy(true);
    setFailure(null);
    setCodeNote(null);
    let current: CreatedBooking | null = created;
    if (!current) {
      const result = await createBooking({
        package_slug: booking.packageSlug,
        travel_date: booking.date,
        pax: booking.pax,
        room: booking.room,
        hotel_category: quote.hotelCategory,
        addons: booking.addons,
        travellers: booking.travellers.map((traveller) => ({
          // Blank optional details go as null; the API names an unnamed traveller "Traveller 2" and so on.
          name: traveller.name.trim() || null,
          passport_number: traveller.passport.trim() || null,
          date_of_birth: parseDayMonthYear(traveller.dob),
          passport_expiry: parseDayMonthYear(traveller.expiry),
          phone: traveller.phone.trim() || null,
          email: traveller.email.trim() || null,
          passport_scan_token: traveller.scanToken,
          ocr_filled: traveller.ocrFilled,
        })),
        expected_total: quote.total,
        terms_accepted: true,
        locale,
        idempotency_key: booking.attemptKey,
        coupon_code: couponCode,
        verification_code: checking ? typedCode : null,
      });
      if (!result.ok) {
        // This attempt was already booked (a second click, or a retry after a lost answer): open that booking.
        const token = result.reason === 'already_created' ? recallBookingToken(result.reference) : null;
        if (result.reason === 'already_created' && token) {
          openBooking(result.reference, token);
          return;
        }
        // The API asks for the code although this page didn't know yet: send it, and open the box.
        if (result.reason === 'verification_required') {
          setBusy(false);
          setVerify(true);
          await requestCode();
          return;
        }
        // Wrong, expired or out of tries: nothing was saved; the customer types it again or asks for a new one.
        if (result.reason === 'verification_invalid') {
          setBusy(false);
          setCodeNote({ tone: 'error', text: result.message ?? t('verify.invalid') });
          return;
        }
        // The coupon stopped working since it was applied: it comes off, the total shown goes back up, and the
        // customer confirms again at that price (or contacts us).
        if (result.reason === 'coupon_invalid') {
          removeCoupon();
          booking.setCouponCheck({ status: 'refused', message: result.message });
        }
        // Priced differently now, perhaps because the coupon's terms changed: check it again for the new discount.
        if (result.reason === 'price_changed' && couponCode) {
          void applyCoupon(couponCode, hotelCategory, locale, { unavailable: t('coupon.unavailable'), rateLimited: t('coupon.rateLimited') });
        }
        setBusy(false);
        setFailure(result);
        return;
      }
      current = {
        reference: result.data.reference,
        token: result.data.accessToken,
        // The API's answer, not the (cached) pricing flag, decides where the customer goes next.
        checkout: result.data.payment.checkout,
      };
      rememberBookingToken(current.reference, current.token);
      booking.setCreated(current);
    }

    // Without the built-in checkout the booking's own page shows how to pay, with the exact amounts.
    if (!current.checkout) {
      openBooking(current.reference, current.token);
      return;
    }
    const payment = await startPayment(current.reference, current.token, booking.method, online.total, locale);
    if (!payment.ok) {
      setBusy(false);
      setFailure(payment);
      return;
    }
    window.location.assign(payment.data.redirectUrl);
  };

  const message = failure ? (failure.reason === 'coupon_invalid' ? t('coupon.refusedAtBooking', { message: failure.message, total: f.bdt(checkout ? online.total : quote.total) }) : failureMessage(failure, t, f)) : null;

  return (
    <>
      <h3 className="text-19 font-semibold">{t('paymentHeading')}</h3>
      <PriceBreakdown
        lines={[...quoteLines(quote, pkg.title, addons, pricing.singleRoomSupplementPercent, t, f, couponCode), ...(checkout ? onlineChargeLine(online, t, f) : [])]}
        total={checkout ? online.total : quote.total}
        totalLabel={t('totalToPay')}
      />

      {checkout ? (
      <fieldset className="flex flex-col gap-2.5">
        <legend className="mb-2 text-14 font-semibold">{t('methodHeading')}</legend>
        <div className="grid-auto-fit-140 grid gap-2.5">
          {METHODS.map((method) => (
            <label
              key={method}
              className={`flex cursor-pointer items-center gap-2.5 rounded-12 border-chip px-3.5 py-3 text-14 font-semibold ${booking.method === method ? 'border-blue bg-paper-alt' : 'border-hairline bg-white'}`}
            >
              <input
                type="radio"
                name="payment-method"
                value={method}
                checked={booking.method === method}
                onChange={() => booking.setMethod(method)}
                className="accent-blue"
              />
              {t(`methods.${method}`)}
            </label>
          ))}
        </div>
        <p className="text-12 text-muted">{t('methodNote')}</p>
      </fieldset>
      ) : (
        <p className="rounded-12 bg-paper-alt px-3.5 py-3 text-13.5 leading-1.55 text-ink-deep" data-testid="confirm-note">{t('confirmNote')}</p>
      )}

      {verify && !created && sentTo ? (
        <PhoneCodeBox
          phone={sentTo}
          code={code}
          onCode={(value) => {
            setCode(value);
            setCodeNote(null);
          }}
          note={codeNote}
          waitSeconds={waitSeconds}
          sending={sending}
          onResend={() => void requestCode()}
          onChangeNumber={() => booking.goTo(2)}
        />
      ) : null}

      {message ? (
        <div role="alert" className="flex flex-col gap-2 rounded-12 bg-orange-tint px-3.5 py-3 text-13.5 leading-1.55 text-amber">
          <span>{message}</span>
          {failure?.reason === 'price_changed' ? (
            <button type="button" onClick={() => window.location.reload()} className={buttonClass('outlineDark', 'none', 'self-start px-4 py-2 text-13')}>
              {t('refreshPrices')}
            </button>
          ) : null}
          {created ? (
            <span className="text-ink-deep">
              {t.rich('createdButNotPaid', {
                reference: created.reference,
                link: (chunks) => (
                  <a href={`${prefix}/booking/${created.reference}#t=${created.token}`} className="font-semibold text-blue underline">
                    {chunks}
                  </a>
                ),
              })}
            </span>
          ) : null}
        </div>
      ) : null}

      <div className="flex flex-wrap items-center gap-2.5">
        <button
          type="button"
          onClick={() => void pay()}
          aria-disabled={busy || couponChecking || sending}
          disabled={busy || couponChecking || sending}
          className={buttonClass('success', 'none', 'px-6.5 py-3.25 text-15')}
        >
          {couponChecking
            ? t('coupon.rechecking')
            : sending
              ? t('verify.sending')
              : checkout
                ? busy
                  ? t('paying')
                  : t('payButton', { total: f.bdt(online.total) })
                : busy
                  ? t('confirming')
                  : t('confirmButton', { total: f.bdt(quote.total) })}
        </button>
        <a
          href={whatsappUrl(settings.contact.whatsapp, t('whatsappHelp', { title: pkg.title }))}
          target="_blank"
          rel="noopener noreferrer"
          className={buttonClass('outlineDark', 'none', 'px-5 py-3 text-14')}
        >
          {t('whatsappConfirm')}
        </a>
      </div>

      {checkout ? (
      <div className="flex items-center gap-2.5 rounded-10 border border-hairline bg-row-alt px-3 py-2.5 text-12 text-muted">
        <span aria-hidden className="text-15 text-green">
          ⛨
        </span>
        <span>{t('secure')}</span>
      </div>
      ) : null}
    </>
  );
}

function failureMessage(failure: ApiFailure, t: ReturnType<typeof useTranslations<'booking'>>, f: ReturnType<typeof useFormatters>): string {
  switch (failure.reason) {
    case 'price_changed':
      return t('priceChanged', { total: f.bdt(failure.total) });
    case 'seats_unavailable':
      return t('seatsUnavailable', {
        count: failure.available,
        countText: f.number(failure.available),
      });
    case 'payment_unavailable':
    case 'gateway_unavailable':
      return failure.message ?? t('paymentUnavailable');
    case 'already_created':
      return t('alreadyCreated', { reference: failure.reference });
    case 'rate_limited':
      return t('rateLimited');
    case 'invalid':
      return failure.message ?? t('invalidBooking');
    // docs/booking-phone-verification.md: no code could be sent to the lead's mobile.
    case 'code_undeliverable':
      return failure.message ?? t('verify.undeliverable');
    default:
      return t('bookingFailed');
  }
}
