'use client';

import { useTranslations } from 'next-intl';

import { Link } from '@/i18n/navigation';
import { useFormatters } from '@/lib/use-formatters';

import { usePortal, type PaymentsData } from './api';
import { LoadState, Pill } from './ui';

const KNOWN_METHODS = ['cash', 'bank_transfer', 'cheque', 'card_terminal', 'bkash', 'nagad', 'rocket', 'sslcommerz'];

/** Payments (§3.4): paid and due from the ledger, and the history from the cash book. The invoice is the receipt. */
export function PaymentsView() {
  const t = useTranslations('portal.payments');
  const f = useFormatters();
  const [view, reload] = usePortal<PaymentsData>('portal/payments');

  if (view.state !== 'ready') return <LoadState state={view.state} onRetry={reload} />;
  const data = view.data;
  const kpis = [
    { label: t('paid'), value: f.bdt(data.paid), note: t('bookings', { count: data.bookings, countText: f.number(data.bookings) }), tone: 'text-green' },
    {
      label: t('due'),
      value: f.bdt(data.due),
      note: data.due > 0 ? t('dueOn', { count: data.openBookings, countText: f.number(data.openBookings) }) : t('nothingDue'),
      tone: data.due > 0 ? 'text-amber' : '',
    },
  ];

  return (
    <>
      <h1 className="sr-only">{t('title')}</h1>
      <div className="grid-auto-fit-200 grid gap-3.5">
        {kpis.map((kpi) => (
          <div key={kpi.label} className="flex flex-col gap-1 rounded-18 border border-portal-line bg-app-surface p-4.5">
            <span className="text-13 text-app-muted">{kpi.label}</span>
            <span className={`font-display text-25 font-extrabold ${kpi.tone}`}>{kpi.value}</span>
            <span className="text-12 text-app-muted">{kpi.note}</span>
          </div>
        ))}
      </div>

      <section aria-labelledby="payment-history" className="overflow-x-auto rounded-18 border border-portal-line bg-app-surface">
        <h2 id="payment-history" className="m-0 border-b border-portal-line px-4.5 py-4 text-17 font-semibold">
          {t('history')}
        </h2>
        {data.history.length === 0 ? <p className="m-0 px-4.5 py-4 text-14 text-app-muted">{t('noPayments')}</p> : null}
        {data.history.map((row) => (
          <div
            key={row.id}
            className="grid grid-cols-[minmax(80px,.8fr)_minmax(140px,1.7fr)_minmax(96px,1fr)_minmax(80px,.8fr)] items-center gap-2.5 border-b border-portal-line px-4.5 py-3.25 text-14 last:border-b-0"
          >
            <span className="font-display text-13 text-app-muted">{f.date(row.at.slice(0, 10))}</span>
            <span className="flex min-w-0 flex-col leading-1.25">
              <span className="font-medium">
                {row.title} · {row.reference}
              </span>
              <span className="text-12 text-app-muted">
                {KNOWN_METHODS.includes(row.method) ? t(`methods.${row.method}`) : row.method}
                {row.externalRef ? ` · ${row.externalRef}` : ''}
                {row.kind === 'charge' ? ` · ${t('onlineCharge')}` : ''}
              </span>
            </span>
            <span className={`font-display font-semibold ${row.amount < 0 ? 'text-red' : ''} ${row.reversed ? 'line-through opacity-60' : ''}`}>
              {row.amount < 0 ? `− ${f.bdt(Math.abs(row.amount))}` : f.bdt(row.amount)}
            </span>
            <span className="flex flex-wrap items-center gap-2 text-12 font-semibold whitespace-nowrap">
              {row.kind === 'reversal' ? <Pill tone="red">{t('reversal')}</Pill> : row.reversed ? <Pill tone="grey">{t('reversed')}</Pill> : null}
              <Link href={`/trips/${row.reference}`}>{t('receipt')}</Link>
            </span>
          </div>
        ))}
      </section>
    </>
  );
}
