'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { useFormatters } from '@/lib/use-formatters';

import { acceptQuotation, usePortal, type QuotationDetail } from './api';
import { Card, Heading, LoadState, QuotationStatusPill } from './ui';

/** A quotation addressed to the customer: opening it records Viewed; a valid one can be accepted, and staff book it (§3.2). */
export function QuotationView({ number }: { number: string }) {
  const t = useTranslations('portal.quotation');
  const tt = useTranslations('portal.trips');
  const locale = useLocale();
  const f = useFormatters();
  const [view, reload, replace] = usePortal<QuotationDetail>(`portal/quotations/${encodeURIComponent(number)}`);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const back = (
    <Link href="/" className="self-start text-13.5 font-semibold">
      ← {t('back')}
    </Link>
  );
  if (view.state !== 'ready') {
    return (
      <>
        {back}
        <LoadState state={view.state} onRetry={reload} />
      </>
    );
  }
  const quotation = view.data;

  const accept = async () => {
    setBusy(true);
    setError(null);
    const result = await acceptQuotation(quotation.number, locale);
    setBusy(false);
    setConfirming(false);
    if (result.ok) replace({ ...quotation, ...result.data });
    else {
      setError(result.message ?? t('acceptFailed'));
      reload();
    }
  };

  return (
    <>
      {back}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-2.5">
          <span className="font-display text-13 text-app-muted">{quotation.number}</span>
          <QuotationStatusPill status={quotation.status} />
        </div>
        <h1 className="m-0 text-fluid-19-25 leading-1.25 font-bold">{quotation.title}</h1>
        <p className="m-0 text-14 text-app-muted">
          {quotation.travelDate ? f.date(quotation.travelDate) : t('dateToBeFixed')} · {tt('travellers', { count: quotation.pax, countText: f.number(quotation.pax) })}
        </p>
        <p className={`m-0 rounded-12 px-3.5 py-3 text-14 ${quotation.status === 'sent' ? 'bg-blue-tint text-blue' : quotation.status === 'accepted' ? 'bg-green-tint text-green' : 'bg-slate-tint text-app-muted'}`}>
          {t(`note.${quotation.status}`, { date: f.date(quotation.validUntil) })}
        </p>
      </Card>

      <Card>
        <Heading title={t('price')} />
        <dl className="flex flex-col gap-2 text-14">
          {quotation.lines.map((line, i) => (
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
          {quotation.discount > 0 ? (
            <div className="flex justify-between gap-3">
              <dt className="text-app-muted">{t('discount')}</dt>
              <dd className="font-display">− {f.bdt(quotation.discount)}</dd>
            </div>
          ) : null}
          {quotation.serviceCharge > 0 ? (
            <div className="flex justify-between gap-3">
              <dt className="text-app-muted">{t('serviceCharge')}</dt>
              <dd className="font-display">{f.bdt(quotation.serviceCharge)}</dd>
            </div>
          ) : null}
          <div className="flex justify-between gap-3 border-t border-portal-line pt-2 font-bold">
            <dt>{t('total')}</dt>
            <dd className="font-display">{f.bdt(quotation.total)}</dd>
          </div>
        </dl>
        <div className="flex flex-wrap gap-2">
          <a href={quotation.url} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineInk', 'sm')}>
            {t('view')}
          </a>
          <a href={quotation.pdfUrl} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineInk', 'sm')}>
            {t('pdf')}
          </a>
        </div>

        {error ? (
          <p role="alert" className="m-0 text-13 text-amber">
            {error}
          </p>
        ) : null}
        {quotation.canAccept ? (
          confirming ? (
            <div className="flex flex-col gap-2.5 rounded-14 border border-portal-line bg-app-surface-2 p-3.5">
              <p className="m-0 text-14">{t('confirmAccept', { total: f.bdt(quotation.total) })}</p>
              <div className="flex flex-wrap gap-2">
                <button type="button" onClick={() => void accept()} disabled={busy} className={buttonClass('success', 'md')}>
                  {t('acceptConfirm')}
                </button>
                <button type="button" onClick={() => setConfirming(false)} className={buttonClass('outlineInk', 'md')}>
                  {t('cancel')}
                </button>
              </div>
            </div>
          ) : (
            <button type="button" onClick={() => setConfirming(true)} className={buttonClass('cta', 'md', 'self-start')}>
              {t('accept')}
            </button>
          )
        ) : null}
      </Card>
    </>
  );
}
