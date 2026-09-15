'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { onlinePayment, type Quote } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { createBooking, rememberBookingToken, startPayment, type ApiFailure } from '@/lib/booking-api';
import type { PackageView } from '@/lib/content/views';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { parseDayMonthYear } from '@/lib/validators';
import { useBooking, type PaymentMethod } from '@/state/booking';

import { onlineChargeLine, PriceBreakdown, quoteLines } from './ReviewStep';

const METHODS: PaymentMethod[] = ['bkash', 'nagad', 'card', 'bank'];

type Created = { reference: string; token: string };

/**
 * Pay the full amount through SSLCommerz (decision 2). The booking is created first — as an unpaid inquiry — so a
 * failed or abandoned payment never loses it; then the API opens an SSLCommerz session and we go there. The total
 * shown is the same quote as every earlier step; if the server's price differs, nothing is charged and the new total
 * is shown instead.
 */
export function PaymentStep({ pkg, quote }: { pkg: PackageView; quote: Quote }) {
  const t = useTranslations('booking');
  const locale = useLocale() as 'bn' | 'en';
  const { addons, pricing, settings } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [created, setCreated] = useState<Created | null>(null);
  // The same amount the review step showed; the API refuses to start a payment for any other.
  const online = onlinePayment(quote.total, pricing.onlinePaymentChargePercent);

  const pay = async () => {
    setBusy(true);
    setFailure(null);
    let current = created;
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
      });
      if (!result.ok) {
        setBusy(false);
        setFailure(result);
        return;
      }
      current = {
        reference: result.data.reference,
        token: result.data.accessToken,
      };
      rememberBookingToken(current.reference, current.token);
      setCreated(current);
    }

    const payment = await startPayment(current.reference, current.token, booking.method, online.total, locale);
    if (!payment.ok) {
      setBusy(false);
      setFailure(payment);
      return;
    }
    window.location.assign(payment.data.redirectUrl);
  };

  const prefix = locale === 'en' ? '/en' : '';
  const message = failure ? failureMessage(failure, t, f) : null;

  return (
    <>
      <h3 className="text-19 font-semibold">{t('paymentHeading')}</h3>
      <PriceBreakdown
        lines={[...quoteLines(quote, pkg.title, addons, pricing.singleRoomSupplementPercent, t, f), ...onlineChargeLine(online, t, f)]}
        total={online.total}
        totalLabel={t('totalToPay')}
      />

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
          aria-disabled={busy}
          disabled={busy}
          className={buttonClass('success', 'none', 'px-6.5 py-3.25 text-15')}
        >
          {busy ? t('paying') : t('payButton', { total: f.bdt(online.total) })}
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

      <div className="flex items-center gap-2.5 rounded-10 border border-hairline bg-row-alt px-3 py-2.5 text-12 text-muted">
        <span aria-hidden className="text-15 text-green">
          ⛨
        </span>
        <span>{t('secure')}</span>
      </div>
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
    case 'rate_limited':
      return t('rateLimited');
    case 'invalid':
      return failure.message ?? t('invalidBooking');
    default:
      return t('bookingFailed');
  }
}
