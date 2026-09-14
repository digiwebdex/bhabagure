'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useCallback, useEffect, useState } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { getBooking, recallBookingToken, startPayment, type PublicBooking } from '@/lib/booking-api';
import { displayPhone, whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import type { PaymentMethod } from '@/state/booking';

type View =
  { state: 'loading' } | { state: 'no-token' } | { state: 'not-found' } | { state: 'unavailable' } | { state: 'ready'; booking: PublicBooking; token: string };

const OPEN_ATTEMPT = ['initiated', 'redirected'];
const POLL_MS = 3000;
const POLL_LIMIT = 40;

export function BookingStatusPanel({ reference }: { reference: string }) {
  const t = useTranslations('bookingStatus');
  const tb = useTranslations('booking');
  const locale = useLocale();
  const f = useFormatters();
  const { settings } = useSiteContent();
  const [view, setView] = useState<View>({ state: 'loading' });
  const [polls, setPolls] = useState(0);
  const [method, setMethod] = useState<PaymentMethod>('bkash');
  const [retrying, setRetrying] = useState(false);
  const [retryError, setRetryError] = useState<string | null>(null);
  const [link, setLink] = useState('');

  const load = useCallback(async () => {
    const token = recallBookingToken(reference);
    if (!token) return setView({ state: 'no-token' });
    const result = await getBooking(reference, token, locale);
    if (result.ok) {
      setView({ state: 'ready', booking: result.data, token });
      setLink(`${window.location.origin}${window.location.pathname}#t=${token}`);
    } else
      setView({
        state: result.reason === 'not_found' ? 'not-found' : 'unavailable',
      });
  }, [reference, locale]);

  useEffect(() => {
    // Loading from the API is the effect's purpose; the state update happens after the request resolves.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  // SSLCommerz may still be confirming (the IPN can arrive after the customer): check again for a while.
  const waiting = view.state === 'ready' && view.booking.paymentStatus !== 'paid' && OPEN_ATTEMPT.includes(view.booking.payment.lastAttempt?.status ?? '');
  useEffect(() => {
    if (!waiting || polls >= POLL_LIMIT) return;
    const timer = setTimeout(() => {
      setPolls((n) => n + 1);
      void load();
    }, POLL_MS);
    return () => clearTimeout(timer);
  }, [waiting, polls, load]);

  const retry = async () => {
    if (view.state !== 'ready') return;
    setRetrying(true);
    setRetryError(null);
    const result = await startPayment(reference, view.token, method, view.booking.payment.online.total, locale);
    if (result.ok) return window.location.assign(result.data.redirectUrl);
    setRetrying(false);
    setRetryError(result.reason === 'payment_unavailable' || result.reason === 'gateway_unavailable' ? (result.message ?? t('retryFailed')) : t('retryFailed'));
  };

  const whatsapp = whatsappUrl(settings.contact.whatsapp, t('whatsappText', { reference }));
  const card = 'flex flex-col gap-4 rounded-18 border border-hairline bg-white p-fluid-18-26';

  if (view.state !== 'ready') {
    return (
      <div className={card}>
        <h1 className="text-fluid-19-23 font-bold tracking-title">{t('title')}</h1>
        <p role="status" className="text-14 leading-1.6 text-muted">
          {view.state === 'loading' ? t('checking') : view.state === 'no-token' ? t('noToken') : view.state === 'not-found' ? t('notFound') : t('unavailable')}
        </p>
        {view.state !== 'loading' ? (
          <a href={whatsapp} target="_blank" rel="noopener noreferrer" className={buttonClass('success', 'lg', 'self-start')}>
            {t('contactUs')}
          </a>
        ) : null}
      </div>
    );
  }

  const { booking } = view;
  const outcome =
    booking.paymentStatus === 'paid'
      ? 'paid'
      : waiting && polls < POLL_LIMIT
        ? 'checking'
        : booking.status === 'cancelled'
          ? 'cancelled'
          : booking.payment.lastAttempt
            ? 'not-paid'
            : 'unpaid';
  const tone = outcome === 'paid' ? 'bg-green-tint text-green' : outcome === 'checking' ? 'bg-paper-alt text-blue' : 'bg-orange-tint text-amber';

  return (
    <div className={card}>
      <div className="flex flex-col gap-1">
        <span className="font-display text-13 text-muted">{booking.reference}</span>
        <h1 className="text-fluid-19-23 font-bold tracking-title">{t(`headline.${outcome}`)}</h1>
      </div>
      <p role="status" aria-live="polite" className={`rounded-12 px-3.5 py-3 text-14 leading-1.6 ${tone}`}>
        {t(`note.${outcome}`)}
      </p>

      <dl className="grid-auto-fit-200 grid gap-3 text-14">
        <Item label={t('package')} value={booking.packageTitle} />
        <Item label={t('travelDate')} value={booking.travelStart ? f.date(booking.travelStart) : '—'} />
        <Item label={t('travellers')} value={booking.travellers.map((tr) => tr.name).join(', ')} />
        <Item label={t('total')} value={f.bdt(booking.total)} />
        <Item label={t('paid')} value={f.bdt(booking.paid)} />
        <Item label={t('due')} value={f.bdt(booking.due)} />
      </dl>

      {booking.invoice ? (
        <div className="flex flex-wrap gap-2.5">
          <a href={booking.invoice.url} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineDark', 'none', 'px-4.5 py-2.75 text-14')}>
            {t('viewInvoice', { number: booking.invoice.number })}
          </a>
          <a href={booking.invoice.pdfUrl} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineDark', 'none', 'px-4.5 py-2.75 text-14')}>
            {t('downloadPdf')}
          </a>
        </div>
      ) : null}

      {booking.payment.canPay && outcome !== 'checking' ? (
        <div className="flex flex-col gap-2.5 rounded-14 border border-hairline p-4">
          <strong className="text-15">{t('payNow', { amount: f.bdt(booking.payment.online.total) })}</strong>
          {booking.payment.online.charge > 0 ? (
            <span className="text-13 text-muted">
              {t('payNowBreakdown', {
                balance: f.bdt(booking.payment.online.amount),
                charge: f.bdt(booking.payment.online.charge),
                percent: f.percent(booking.payment.online.chargePercent),
              })}
            </span>
          ) : null}
          <div className="flex flex-wrap gap-2">
            {(['bkash', 'nagad', 'card', 'bank'] as const).map((value) => (
              <label
                key={value}
                className={`flex cursor-pointer items-center gap-2 rounded-10 border-chip px-3 py-2 text-13 font-semibold ${method === value ? 'border-blue bg-paper-alt' : 'border-hairline'}`}
              >
                <input type="radio" name="retry-method" checked={method === value} onChange={() => setMethod(value)} className="accent-blue" />
                {tb(`methods.${value}`)}
              </label>
            ))}
          </div>
          {retryError ? (
            <p role="alert" className="text-13 text-amber">
              {retryError}
            </p>
          ) : null}
          <button type="button" onClick={() => void retry()} disabled={retrying} className={buttonClass('success', 'none', 'self-start px-5.5 py-3 text-15')}>
            {retrying ? tb('paying') : t('payButton')}
          </button>
        </div>
      ) : null}

      <div className="flex flex-col gap-1.5 rounded-12 bg-row-alt px-3.5 py-3 text-13 leading-1.55 text-muted">
        <strong className="text-ink-deep">{t('privateLinkTitle')}</strong>
        <span>{t('privateLinkNote')}</span>
        <input
          readOnly
          value={link}
          onFocus={(e) => e.currentTarget.select()}
          aria-label={t('privateLinkTitle')}
          className="rounded-9 border border-hairline bg-white px-2.5 py-2 font-display text-12"
        />
      </div>

      {settings.contact.notificationsWhatsapp ? (
        <p className="text-13 leading-1.55 text-muted" data-testid="notifications-number">
          {t('notificationsNote', {
            number: f.digits(displayPhone(settings.contact.notificationsWhatsapp)),
            main: f.digits(displayPhone(settings.contact.phone)),
          })}
        </p>
      ) : null}
      <a href={whatsapp} target="_blank" rel="noopener noreferrer" className={buttonClass('success', 'lg', 'self-start')}>
        {t('contactUs')}
      </a>
    </div>
  );
}

function Item({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-12 text-muted">{label}</dt>
      <dd className="font-semibold">{value}</dd>
    </div>
  );
}
