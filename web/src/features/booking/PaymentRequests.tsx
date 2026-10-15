'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { controlClass, Field } from '@/components/ui/Field';
import type { PaymentRequestRow } from '@/lib/booking-api';
import { useFormatters } from '@/lib/use-formatters';

/** The API's limit for a screenshot (EvidenceStore::MAX_KB). */
const MAX_BYTES = 5 * 1024 * 1024;

/** Sends one request (multipart); the booking page uses its private token, the portal the signed-in customer. */
export type SendPaymentRequest = (form: FormData) => Promise<{ ok: true } | { ok: false; message?: string }>;

/** Which of the How to pay boxes the form sits under. */
export type PaidInto = { method: 'bank_transfer'; accountNumber: string } | { method: 'bkash' };

/**
 * "I've paid" (docs/payment-requests.md), under a bank account or bKash on How to pay: the amount, the date, the
 * transaction or reference number (bKash's is required) and a screenshot. Staff check it and approve it in the admin.
 */
export function PaymentRequestForm({ into, due, send, onSent, portal = false }: { into: PaidInto; due: number; send: SendPaymentRequest; onSent: () => void; portal?: boolean }) {
  const t = useTranslations('payRequest');
  const f = useFormatters();
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState(String(due));
  const [paidOn, setPaidOn] = useState(today);
  const [reference, setReference] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tried, setTried] = useState(false);
  const bkash = into.method === 'bkash';
  const value = Number(amount);
  const tooBig = file !== null && file.size > MAX_BYTES;
  const problems = {
    amount: !(value >= 1) ? t('amountRequired') : value > due ? t('amountOverDue', { due: f.bdt(due) }) : null,
    reference: bkash && !reference.trim() ? t('referenceRequired') : null,
    file: !file ? t('screenshotRequired') : tooBig ? t('screenshotTooBig') : null,
  };
  const invalid = Object.values(problems).some(Boolean) || !paidOn;

  if (!open) {
    return (
      <button type="button" onClick={() => setOpen(true)} className={buttonClass('outlineDark', 'none', 'self-start px-4 py-2.5 text-13.5')} data-testid="paid-open">
        {t('open')}
      </button>
    );
  }

  const submit = async () => {
    setTried(true);
    if (invalid || !file) return;
    const form = new FormData();
    form.append('method', into.method);
    if (into.method === 'bank_transfer') form.append('account_number', into.accountNumber);
    form.append('amount', String(value));
    form.append('paid_on', paidOn);
    if (reference.trim()) form.append('reference', reference.trim());
    form.append('screenshot', file);
    setSending(true);
    setError(null);
    const result = await send(form);
    setSending(false);
    if (result.ok) {
      setOpen(false);
      setFile(null);
      setReference('');
      setTried(false);
      onSent();
    } else setError(result.message ?? t('failed'));
  };

  return (
    <div className={`flex flex-col gap-3 rounded-12 border p-3.5 ${portal ? 'border-portal-line' : 'border-hairline bg-paper-alt'}`} data-testid="paid-form">
      <strong className="text-14">{t('title')}</strong>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('amount')} error={tried ? problems.amount : null}>
          <input value={amount} onChange={(event) => setAmount(event.target.value)} inputMode="decimal" className={controlClass(tried && !!problems.amount)} />
        </Field>
        <Field label={t('paidOn')}>
          <input type="date" value={paidOn} max={today} onChange={(event) => setPaidOn(event.target.value)} className={controlClass(false)} />
        </Field>
      </div>
      {bkash ? <span className="-mt-1 text-12 text-muted">{t('bkashAmountNote')}</span> : null}
      <Field label={bkash ? t('referenceBkash') : t('reference')} error={tried ? problems.reference : null}>
        <input value={reference} onChange={(event) => setReference(event.target.value)} maxLength={100} className={controlClass(tried && !!problems.reference)} />
      </Field>
      <Field label={t('screenshot')} error={tried ? problems.file : null}>
        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-13 text-ink" />
      </Field>
      <span className="-mt-1 text-12 text-muted">{t('screenshotHint')}</span>
      {error ? (
        <p role="alert" className="m-0 text-13 font-semibold text-red">
          {error}
        </p>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => void submit()} disabled={sending} className={buttonClass('success', 'none', 'px-4.5 py-2.75 text-14')}>
          {sending ? t('sending') : t('send')}
        </button>
        <button type="button" onClick={() => setOpen(false)} className={buttonClass('outlineDark', 'none', 'px-4 py-2.75 text-14')}>
          {t('cancel')}
        </button>
      </div>
    </div>
  );
}

/** What the customer sent and what became of it: waiting, received, or not accepted and why. */
export function PaymentRequestList({ requests, justSent = false }: { requests: PaymentRequestRow[]; justSent?: boolean }) {
  const t = useTranslations('payRequest');
  const f = useFormatters();
  if (requests.length === 0 && !justSent) return null;
  const tone = { pending: 'bg-orange-tint text-amber', approved: 'bg-green-tint text-green-deep', rejected: 'bg-red-tint text-red' } as const;

  return (
    <div className="flex flex-col gap-2" data-testid="payment-requests">
      {justSent ? (
        <p role="status" className="m-0 rounded-12 bg-green-tint px-3.5 py-2.5 text-13.5 text-green-deep">
          {t('sent')}
        </p>
      ) : null}
      {requests.length > 0 ? <strong className="text-14">{t('listTitle')}</strong> : null}
      <ul className="m-0 flex list-none flex-col gap-2 p-0">
        {requests.map((request) => (
          <li key={request.id} className="flex flex-wrap items-center justify-between gap-2 rounded-12 border border-hairline px-3.5 py-2.5 text-13.5">
            <span className="flex min-w-0 flex-col">
              <span className="font-semibold">{f.bdt(request.approvedAmount ?? request.amount)}</span>
              <span className="text-12.5 text-muted">
                {request.bankName} · {f.date(request.paidOn)}
                {request.reference ? ` · ${request.reference}` : ''}
              </span>
              {request.status === 'rejected' && request.note ? <span className="text-12.5 text-red">{t('reason', { note: request.note })}</span> : null}
            </span>
            <span className={`rounded-pill px-2.5 py-1 text-12 font-semibold ${tone[request.status]}`}>{t(`status.${request.status}`)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
