'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { openPortalFile, portalCall } from '@/lib/customer-api';
import { useFormatters } from '@/lib/use-formatters';
import type { PaymentMethod } from '@/state/booking';

import { usePortal, type TripDetail } from './api';
import { BookingStatusPill, Card, dateRange, Heading, LoadState } from './ui';

const METHODS: PaymentMethod[] = ['bkash', 'nagad', 'card', 'bank'];
const OPEN_ATTEMPT = ['initiated', 'redirected'];

/** One trip: amounts and paying the balance online, readiness, travellers and the planned itinerary (§3.2). */
export function TripDetailView({ reference }: { reference: string }) {
  const t = useTranslations('portal.trip');
  const tt = useTranslations('portal.trips');
  const tb = useTranslations('booking');
  const locale = useLocale();
  const f = useFormatters();
  const [view, reload] = usePortal<TripDetail>(`portal/trips/${encodeURIComponent(reference)}`);
  const [method, setMethod] = useState<PaymentMethod>('bkash');
  const [paying, setPaying] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);

  if (view.state !== 'ready') {
    return (
      <>
        <BackLink />
        <LoadState state={view.state} onRetry={reload} />
      </>
    );
  }
  const trip = view.data;
  const checking = trip.paymentStatus !== 'paid' && OPEN_ATTEMPT.includes(trip.payment.lastAttempt?.status ?? '');

  const pay = async () => {
    setPaying(true);
    setPayError(null);
    const result = await portalCall<{ redirectUrl: string }>(`public/bookings/${encodeURIComponent(trip.reference)}/payments`, locale, {
      method: 'POST',
      body: JSON.stringify({ method, expected_total: trip.payment.online.total, return_to: 'portal' }),
    });
    if (result.ok) return window.location.assign(result.data.redirectUrl);
    setPaying(false);
    if (result.code === 'price_changed') {
      setPayError(t('priceChanged'));
      reload();
    } else setPayError(result.code === 'payment_unavailable' || result.code === 'gateway_unavailable' ? (result.message ?? t('payFailed')) : t('payFailed'));
  };

  const row = (label: string, value: string, strong = false) => (
    <div className={`flex justify-between gap-3 ${strong ? 'font-bold' : ''}`}>
      <dt className={strong ? '' : 'text-app-muted'}>{label}</dt>
      <dd className="font-display">{value}</dd>
    </div>
  );

  return (
    <>
      <BackLink />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-2.5">
          <span className="font-display text-13 text-app-muted">{trip.reference}</span>
          <BookingStatusPill status={trip.status} />
        </div>
        <h1 className="m-0 text-fluid-19-25 leading-1.25 font-bold">{trip.packageTitle}</h1>
        <p className="m-0 text-14 text-app-muted">
          {dateRange(f.date, trip.travelStart, trip.travelEnd)} · {tt('travellers', { count: trip.pax, countText: f.number(trip.pax) })}
          {trip.daysToGo !== null ? ` · ${trip.daysToGo === 0 ? tt('departsToday') : tt('daysToGo', { count: trip.daysToGo, countText: f.number(trip.daysToGo) })}` : ''}
        </p>
      </Card>

      <div className="grid-auto-fit-320 grid items-start gap-4.5">
        <Card>
          <Heading title={t('amounts')} />
          <dl className="flex flex-col gap-2 text-14">
            {trip.lines.map((line, i) => (
              <div key={i} className="flex justify-between gap-3">
                <dt className="min-w-0">
                  {line.title}
                  <span className="block text-12 text-app-muted">
                    {f.number(line.quantity)} × {f.bdt(line.unitPrice)}
                  </span>
                </dt>
                <dd className="font-display">{f.bdt(line.amount)}</dd>
              </div>
            ))}
            {trip.discount > 0 ? row(t('discount'), `− ${f.bdt(trip.discount)}`) : null}
            {trip.serviceCharge > 0 ? row(t('serviceCharge', { percent: f.percent(trip.chargePercent) }), f.bdt(trip.serviceCharge)) : null}
            <div className="border-t border-portal-line pt-2">{row(t('total'), f.bdt(trip.total), true)}</div>
            {row(t('paid'), f.bdt(trip.paid))}
            <div className={trip.due > 0 ? 'text-amber' : ''}>{row(t('due'), f.bdt(trip.due), true)}</div>
          </dl>

          {trip.invoice ? (
            <div className="flex flex-wrap gap-2">
              <a href={trip.invoice.url} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineInk', 'sm')}>
                {t('viewInvoice', { number: trip.invoice.number })}
              </a>
              <a href={trip.invoice.pdfUrl} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineInk', 'sm')}>
                {t('invoicePdf')}
              </a>
            </div>
          ) : null}

          {checking ? (
            <p role="status" className="flex flex-wrap items-center gap-3 rounded-12 bg-blue-tint px-3.5 py-3 text-13.5 text-blue">
              {t('checkingPayment')}
              <button type="button" onClick={reload} className="cursor-pointer font-semibold underline">
                {t('refresh')}
              </button>
            </p>
          ) : trip.payment.canPay ? (
            <div className="flex flex-col gap-3 rounded-14 border border-portal-line bg-app-surface-2 p-3.5">
              <strong className="text-15">{t('payBalance')}</strong>
              <div className="flex flex-wrap gap-2">
                {METHODS.map((value) => (
                  <label
                    key={value}
                    className={`flex cursor-pointer items-center gap-2 rounded-10 border-chip px-3 py-2 text-13 font-semibold ${method === value ? 'border-blue bg-blue-wash' : 'border-portal-line bg-app-surface'}`}
                  >
                    <input type="radio" name="portal-pay-method" checked={method === value} onChange={() => setMethod(value)} className="accent-blue" />
                    {tb(`methods.${value}`)}
                  </label>
                ))}
              </div>
              <dl className="flex flex-col gap-1.5 text-13.5">
                {row(t('balance'), f.bdt(trip.payment.online.amount))}
                {trip.payment.online.charge > 0 ? row(t('onlineCharge', { percent: f.percent(trip.payment.online.chargePercent) }), f.bdt(trip.payment.online.charge)) : null}
                {row(t('toPay'), f.bdt(trip.payment.online.total), true)}
              </dl>
              {payError ? (
                <p role="alert" className="m-0 text-13 text-amber">
                  {payError}
                </p>
              ) : null}
              <button type="button" onClick={() => void pay()} disabled={paying} className={buttonClass('success', 'md', 'self-start')}>
                {paying ? t('opening') : t('payNow', { amount: f.bdt(trip.payment.online.total) })}
              </button>
            </div>
          ) : null}
        </Card>

        <div className="flex flex-col gap-4.5">
          {trip.upcoming ? (
            <Card>
              <Heading title={tt('readiness')} aside={<span className="text-13 text-app-muted">{f.number(trip.readiness.done)}/{f.number(trip.readiness.total)}</span>} />
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {trip.readiness.checks.map((check) => (
                  <li key={check.key} className="flex items-start gap-2.5 text-14">
                    <span aria-hidden className={`mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-5 text-11 font-extrabold text-white ${check.done ? 'bg-green' : 'bg-amber'}`}>
                      {check.done ? '✓' : '!'}
                    </span>
                    <span>{check.done ? tt(`checks.${check.key}.done`) : tt(`checks.${check.key}.waiting`, { names: check.waitingOn.join(', ') })}</span>
                  </li>
                ))}
              </ul>
              {trip.readiness.checks.some((check) => !check.done && ['passports', 'documents'].includes(check.key)) ? (
                <Link href="/documents" className={buttonClass('primary', 'sm', 'self-start')}>
                  {t('openDocuments')}
                </Link>
              ) : null}
            </Card>
          ) : null}
          {trip.tickets.length > 0 ? (
            <Card>
              <Heading title={t('etickets')} />
              <ul className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="portal-tickets">
                {trip.tickets.map((ticket) => (
                  <li key={ticket.id} className="flex flex-col gap-0.5 rounded-12 bg-app-surface-2 px-3.5 py-2.75 text-14">
                    <strong className="font-semibold">{ticket.traveller}</strong>
                    <span className="font-display text-13 text-app-muted">
                      {ticket.airline} · PNR {ticket.pnr} · {f.digits(ticket.ticketNumber)}
                      {ticket.route ? ` · ${ticket.route}` : ''}
                      {ticket.departsOn ? ` · ${f.date(ticket.departsOn)}` : ''}
                    </span>
                    {ticket.hasFile ? (
                      <button type="button" onClick={() => void openPortalFile(`portal/tickets/${ticket.id}/file`, locale)} className="cursor-pointer self-start text-13 font-semibold text-blue hover:text-orange">
                        {t('downloadTicket')}
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
          <Card>
            <Heading title={t('travellers')} />
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0 text-14">
              {trip.travellers.map((traveller, i) => (
                <li key={i}>{traveller.name}</li>
              ))}
            </ul>
          </Card>
        </div>
      </div>

      {trip.itinerary.length > 0 ? (
        <Card>
          <Heading title={t('itinerary')} aside={<span className="text-12 text-app-muted">{t('itineraryNote')}</span>} />
          <ol className="m-0 flex list-none flex-col gap-3 p-0">
            {trip.itinerary.map((day) => (
              <li key={day.day} className="flex gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-10 bg-blue-wash font-display text-13 font-bold text-blue">{f.number(day.day)}</span>
                <div className="flex min-w-0 flex-col gap-0.5">
                  <strong className="text-14.5 font-semibold">{day.title}</strong>
                  {day.body ? <p className="m-0 text-13.5 leading-1.6 whitespace-pre-line text-app-muted">{day.body}</p> : null}
                </div>
              </li>
            ))}
          </ol>
        </Card>
      ) : null}
    </>
  );
}

function BackLink() {
  const t = useTranslations('portal.trip');
  return (
    <Link href="/" className="self-start text-13.5 font-semibold">
      ← {t('back')}
    </Link>
  );
}
