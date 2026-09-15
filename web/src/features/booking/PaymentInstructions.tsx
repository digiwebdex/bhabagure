'use client';

import { useTranslations } from 'next-intl';
import { useState, type ReactNode } from 'react';

import { buttonClass } from '@/components/ui/button';
import type { ManualPayment } from '@/lib/booking-api';
import { useFormatters } from '@/lib/use-formatters';

/**
 * How to pay a booking's balance by hand (docs/phase-8-visa-quotes-pricing-downloads.md §4.F): NPSB bank transfer, the
 * SSLCommerz payment link while the built-in checkout is off, and bKash with its charge already added. Every figure
 * comes from the API, so the booking page, the portal, the PDFs and the WhatsApp message quote the same amounts.
 */
export function PaymentInstructions({ manual, reference, portal = false, alongsideCheckout = false }: { manual: ManualPayment; reference: string; portal?: boolean; alongsideCheckout?: boolean }) {
  const t = useTranslations('payHow');
  const f = useFormatters();
  const box = portal ? 'border-portal-line bg-app-surface' : 'border-hairline bg-white';

  return (
    <section aria-labelledby="pay-how" className="flex flex-col gap-3" data-testid="payment-instructions">
      <div className="flex flex-col gap-0.5">
        <h2 id="pay-how" className="text-16 font-semibold">
          {alongsideCheckout ? t('orHeading') : t('heading')}
        </h2>
        <span className="text-13 leading-1.55 text-muted">{t('reference', { reference })}</span>
      </div>

      {manual.bank ? (
        <div className={`flex flex-col gap-2 rounded-14 border p-3.5 ${box}`} data-testid="pay-bank">
          <strong className="text-14">{t('bankTitle', { type: manual.bank.transferType })}</strong>
          <dl className="m-0 grid gap-x-4 gap-y-1.5 text-13.5 sm:grid-cols-[auto_1fr]">
            <Row label={t('bankName')} value={manual.bank.bankName} />
            <Row label={t('accountName')} value={manual.bank.accountName} />
            <Row label={t('accountNumber')} value={manual.bank.accountNumber} copy />
            <Row label={t('branch')} value={manual.bank.branch} />
            <Row label={t('routingNumber')} value={manual.bank.routingNumber} copy />
            <Row label={t('amount')} value={f.bdt(manual.amount)} strong />
          </dl>
        </div>
      ) : null}

      {manual.link ? (
        <div className={`flex flex-col gap-2 rounded-14 border p-3.5 ${box}`} data-testid="pay-link">
          <strong className="text-14">{t('linkTitle')}</strong>
          <span className="text-13.5 leading-1.55 text-muted">{t('linkNote', { amount: f.bdt(manual.amount), reference })}</span>
          <a href={manual.link} target="_blank" rel="noopener noreferrer" className={buttonClass('success', 'md', 'self-start')}>
            {t('linkButton')}
          </a>
        </div>
      ) : null}

      {manual.bkash ? (
        <div className={`flex flex-col gap-2 rounded-14 border p-3.5 ${box}`} data-testid="pay-bkash">
          <strong className="text-14">{t('bkashTitle')}</strong>
          <dl className="m-0 grid gap-x-4 gap-y-1.5 text-13.5 sm:grid-cols-[auto_1fr]">
            <Row label={t('bkashNumber')} value={localNumber(manual.bkash.number)} copy />
            <Row label={t('balance')} value={f.bdt(manual.amount)} />
            <Row label={t('bkashCharge', { percent: f.percent(manual.bkash.chargePercent) })} value={f.bdt(manual.bkash.charge)} />
            <Row label={t('bkashSend')} value={f.bdt(manual.bkash.total)} strong />
          </dl>
          <span className="text-12.5 leading-1.5 text-muted">{t('bkashNote', { reference })}</span>
        </div>
      ) : null}

      <p className="m-0 text-12.5 leading-1.55 text-muted">{t('afterPaying')}</p>
    </section>
  );
}

/** bKash is sent to the local form of the number: 01XXXXXXXXX. */
function localNumber(phone: string): string {
  const digits = phone.replace(/\D/g, '');
  return digits.startsWith('880') ? `0${digits.slice(3)}` : phone;
}

function Row({ label, value, copy = false, strong = false }: { label: string; value: string; copy?: boolean; strong?: boolean }): ReactNode {
  return (
    <>
      <dt className="text-muted">{label}</dt>
      <dd className={`m-0 flex flex-wrap items-center gap-2 ${strong ? 'font-bold text-ink-deep' : 'font-semibold'}`}>
        <span className="font-display">{value}</span>
        {copy ? <CopyButton value={value} label={label} /> : null}
      </dd>
    </>
  );
}

function CopyButton({ value, label }: { value: string; label: string }) {
  const t = useTranslations('payHow');
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // No clipboard (an old browser or a denied permission): the number stays visible to copy by hand.
    }
  };
  return (
    <button type="button" onClick={() => void copy()} aria-label={t('copy', { what: label })} className="cursor-pointer rounded-8 border border-hairline px-2 py-0.5 text-12 font-semibold text-blue hover:border-blue">
      {copied ? t('copied') : t('copyShort')}
    </button>
  );
}
