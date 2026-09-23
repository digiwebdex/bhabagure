'use client';

import { useLocale, useTranslations } from 'next-intl';

import { onlinePayment, type OnlinePayment, type Quote } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import type { PackageView } from '@/lib/content/views';
import { displayPhone } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking } from '@/state/booking';

import { CouponBox } from './CouponBox';

/**
 * Every line and the total from the shared pricing service, then consent to the (draft) terms. The total here is what
 * the customer will be charged: when an online payment charge is configured it appears as its own line and is included
 * — the gateway is never allowed to add anything after this step.
 */
export function ReviewStep({ pkg, quote, hotelCategory, termsError }: { pkg: PackageView; quote: Quote; hotelCategory: string | null; termsError?: string }) {
  const t = useTranslations('booking');
  const locale = useLocale();
  const { addons, pricing, settings } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();
  const prefix = locale === 'en' ? '/en' : '';

  const online = onlinePayment(quote.total, pricing.onlinePaymentChargePercent);
  const lines = [...quoteLines(quote, pkg.title, addons, pricing.singleRoomSupplementPercent, t, f, booking.coupon?.code), ...onlineChargeLine(online, t, f)];
  const dateText = booking.date ? f.date(booking.date) : t('dateNotChosen');

  return (
    <>
      <h3 className="text-19 font-semibold">{t('reviewHeading')}</h3>

      <dl className="grid-auto-fit-200 grid gap-3 text-14">
        <div className="flex flex-col gap-0.5">
          <dt className="text-12 text-muted">{t('dateSummary')}</dt>
          <dd className="font-semibold">{dateText}</dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-12 text-muted">{t('travellersSummary')}</dt>
          <dd className="font-semibold">
            {booking.travellers
              .map((tr) => tr.name.trim())
              .filter(Boolean)
              .join(', ')}
          </dd>
        </div>
      </dl>

      <PriceBreakdown lines={lines} total={online.total} totalLabel={online.charge > 0 || quote.discount > 0 ? t('totalToPay') : t('total')} />
      <CouponBox hotelCategory={hotelCategory} />

      <label
        className={`-mx-2.5 -my-2 flex items-start gap-2.5 rounded-10 border-chip px-2.5 py-2 text-13 leading-1.5 text-muted ${termsError ? 'border-orange-bright' : 'border-transparent'}`}
      >
        <input type="checkbox" checked={booking.terms} onChange={(e) => booking.setTerms(e.target.checked)} className="mt-0.75 size-4 shrink-0 accent-blue" />
        <span>
          {t.rich('termsLinks', {
            terms: (chunks) => (
              <a href={`${prefix}/terms`} target="_blank" rel="noopener" className="text-blue underline">
                {chunks}
              </a>
            ),
            refund: (chunks) => (
              <a href={`${prefix}/refund-policy`} target="_blank" rel="noopener" className="text-blue underline">
                {chunks}
              </a>
            ),
            privacy: (chunks) => (
              <a href={`${prefix}/privacy`} target="_blank" rel="noopener" className="text-blue underline">
                {chunks}
              </a>
            ),
          })}
        </span>
      </label>
      {termsError ? (
        <p role="alert" className="-mt-2 text-12.5 font-semibold text-amber">
          {termsError}
        </p>
      ) : null}
      <p className="text-12.5 leading-1.55 text-muted">
        {settings.contact.notificationsWhatsapp
          ? t('updatesNoticeFrom', { number: f.digits(displayPhone(settings.contact.notificationsWhatsapp)) })
          : t('updatesNotice')}
      </p>
    </>
  );
}

type Translate = ReturnType<typeof useTranslations<'booking'>>;
type Formatters = ReturnType<typeof useFormatters>;

export type BreakdownLine = { label: string; amount: number; tone?: 'subtotal' | 'discount' };

/**
 * The quote's lines. With a coupon (docs/coupons.md) the lines are totalled, the coupon comes off, and the service
 * charge follows on what is left — the order the API prices in. Without one, exactly the lines as before.
 */
export function quoteLines(quote: Quote, title: string, addons: { code: string; name: string }[], singlePercent: number, t: Translate, f: Formatters, couponCode?: string | null): BreakdownLine[] {
  const paxText = f.number(quote.pax);
  return [
    { label: t('lineBase', { title: quote.hotelCategory ? `${title} · ${t(`hotelCategories.${quote.hotelCategory}`)}` : title, paxText }), amount: quote.subtotal },
    ...(quote.singleSupplement
      ? [
          {
            label: t('lineSingle', { percent: f.percent(singlePercent) }),
            amount: quote.singleSupplement,
          },
        ]
      : []),
    ...quote.addons.map((line) => ({
      label: addons.find((a) => a.code === line.code)?.name ?? line.code,
      amount: line.amount,
    })),
    ...(quote.discount > 0
      ? [
          { label: t('coupon.subtotal'), amount: quote.lines.reduce((sum, line) => sum + line.amount, 0), tone: 'subtotal' as const },
          { label: t('coupon.line', { code: couponCode ?? '' }), amount: quote.discount, tone: 'discount' as const },
        ]
      : []),
    {
      label: t('lineService', { percent: f.percent(quote.chargePercent) }),
      amount: quote.serviceCharge,
    },
  ];
}

/** The "online payment charge" line, only when one is configured (0 = the company absorbs the gateway fee). */
export function onlineChargeLine(online: OnlinePayment, t: Translate, f: Formatters) {
  return online.charge > 0 ? [{ label: t('lineOnlineCharge', { percent: f.percent(online.chargePercent) }), amount: online.charge }] : [];
}

export function PriceBreakdown({ lines, total, totalLabel }: { lines: BreakdownLine[]; total: number; totalLabel: string }) {
  const f = useFormatters();
  return (
    <div className="flex flex-col gap-2 rounded-14 bg-paper-alt p-4">
      {lines.map((line) => (
        <div
          key={line.label}
          data-testid={line.tone === 'discount' ? 'booking-coupon-line' : undefined}
          className={`flex justify-between gap-3 text-14 ${line.tone === 'subtotal' ? 'border-t border-hairline pt-2' : ''}`}
        >
          <span className={`min-w-0 ${line.tone === 'discount' ? 'font-semibold text-green' : line.tone === 'subtotal' ? 'font-semibold text-ink-deep' : 'text-muted'}`}>{line.label}</span>
          <span className={`font-display font-semibold whitespace-nowrap ${line.tone === 'discount' ? 'text-green' : ''}`}>
            {line.tone === 'discount' ? `− ${f.bdt(line.amount)}` : f.bdt(line.amount)}
          </span>
        </div>
      ))}
      <div aria-hidden className="my-1 h-px bg-hairline" />
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-15 font-semibold">{totalLabel}</span>
        <span data-testid="booking-total" className="font-display text-28 font-extrabold text-orange-deep">
          {f.bdt(total)}
        </span>
      </div>
    </div>
  );
}
