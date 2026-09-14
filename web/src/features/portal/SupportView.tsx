'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { Field, controlClass } from '@/components/ui/Field';
import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { useFormatters } from '@/lib/use-formatters';

import { openTicket, sendTicketMessage, usePortal, type SupportData, type TicketDetail } from './api';
import { Card, Heading, LoadState, TicketStatusPill } from './ui';

/** Support (§3.5): the customer's tickets and a new request, optionally about one of their trips. */
export function SupportView() {
  const t = useTranslations('portal.support');
  const f = useFormatters();
  const locale = useLocale();
  const [view, reload] = usePortal<SupportData>('portal/support');
  const [booking, setBooking] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (view.state !== 'ready') return <LoadState state={view.state} onRetry={reload} />;
  const errors = { subject: subject.trim().length >= 3 ? undefined : t('subjectRequired'), body: body.trim().length >= 2 ? undefined : t('bodyRequired') };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (errors.subject || errors.body || busy) return;
    setBusy(true);
    setError(null);
    const result = await openTicket({ bookingReference: booking || null, subject: subject.trim(), body: body.trim() }, locale);
    setBusy(false);
    if (!result.ok) return setError(result.reason === 'rate_limited' ? t('tooMany') : t('sendFailed'));
    setSent(result.data.number);
    setSubject('');
    setBody('');
    setAttempted(false);
    reload();
  };

  return (
    <div className="grid-auto-fit-280 grid items-start gap-4.5">
      <Card>
        <Heading as="h1" title={t('tickets')} />
        {view.data.tickets.length === 0 ? <p className="m-0 text-14 text-app-muted">{t('noTickets')}</p> : null}
        {view.data.tickets.map((ticket) => (
          <Link
            key={ticket.number}
            href={`/support/${ticket.number}`}
            className="flex flex-col gap-1 rounded-12 bg-app-surface-2 p-3.25 text-app-text hover:text-app-text hover:ring-1 hover:ring-blue"
          >
            <span className="flex items-start justify-between gap-2.5">
              <span className="min-w-0 text-14 font-semibold">{ticket.subject}</span>
              <TicketStatusPill status={ticket.status} />
            </span>
            <span className="text-12 text-app-muted">
              {ticket.number}
              {ticket.bookingReference ? ` · ${ticket.bookingReference}` : ''} · {f.date(ticket.createdAt.slice(0, 10))}
            </span>
            {ticket.lastMessage ? (
              <span className="line-clamp-2 text-13 leading-1.5">
                {ticket.lastMessage.author === 'staff' ? `${t('reply')}: ` : ''}
                {ticket.lastMessage.body}
              </span>
            ) : null}
          </Link>
        ))}
      </Card>

      <Card>
        <Heading title={t('newTicket')} />
        {sent ? (
          <p role="status" className="m-0 rounded-12 bg-green-tint p-3.5 text-14 font-semibold text-green">
            {t('sent', { number: sent })}
          </p>
        ) : null}
        <form onSubmit={submit} noValidate className="flex flex-col gap-2.75">
          <Field label={t('booking')} variant="form">
            <select value={booking} onChange={(e) => setBooking(e.target.value)} className={controlClass(false, 'form')}>
              <option value="">{t('noBooking')}</option>
              {view.data.bookings.map((b) => (
                <option key={b.reference} value={b.reference}>
                  {b.reference} · {b.title}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t('subject')} error={attempted ? errors.subject : undefined} variant="form">
            <input value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={160} placeholder={t('subjectPh')} className={controlClass(attempted && !!errors.subject, 'form')} />
          </Field>
          <Field label={t('details')} error={attempted ? errors.body : undefined} variant="form">
            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={4} maxLength={4000} placeholder={t('detailsPh')} className={`${controlClass(attempted && !!errors.body, 'form')} resize-y`} />
          </Field>
          {error ? (
            <p role="alert" className="m-0 text-13 font-semibold text-red">
              {error}
            </p>
          ) : null}
          <button type="submit" disabled={busy} className={buttonClass('cta', 'block')}>
            {t('send')}
          </button>
          <span className="text-12 text-app-muted">{t('replyPromise')}</span>
        </form>
      </Card>
    </div>
  );
}

/** One ticket: the conversation and a reply box. */
export function TicketView({ number }: { number: string }) {
  const t = useTranslations('portal.support');
  const f = useFormatters();
  const locale = useLocale();
  const [view, reload, replace] = usePortal<TicketDetail>(`portal/support/${encodeURIComponent(number)}`);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const back = (
    <Link href="/support" className="self-start text-13.5 font-semibold">
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
  const ticket = view.data;

  const send = async (event: FormEvent) => {
    event.preventDefault();
    if (body.trim().length < 2 || busy) return;
    setBusy(true);
    setError(null);
    const result = await sendTicketMessage(ticket.number, body.trim(), locale);
    setBusy(false);
    if (!result.ok) return setError(result.reason === 'rate_limited' ? t('tooMany') : t('sendFailed'));
    replace(result.data);
    setBody('');
  };

  return (
    <>
      {back}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-2.5">
          <span className="font-display text-13 text-app-muted">
            {ticket.number}
            {ticket.bookingReference ? ` · ${ticket.bookingReference}` : ''}
          </span>
          <TicketStatusPill status={ticket.status} />
        </div>
        <h1 className="m-0 text-fluid-19-23 font-bold">{ticket.subject}</h1>
        <ol className="m-0 flex list-none flex-col gap-2.5 p-0">
          {ticket.messages.map((message) => (
            <li
              key={message.id}
              className={`flex max-w-[85%] flex-col gap-1 rounded-14 px-3.5 py-2.75 text-14 leading-1.55 ${
                message.author === 'customer' ? 'self-end bg-blue-wash' : 'self-start border border-portal-line bg-app-surface-2'
              }`}
            >
              <span className="text-11 font-semibold text-app-muted">
                {message.author === 'customer' ? t('you') : t('staff', { name: message.staffName ?? t('team') })} · {f.date(message.at.slice(0, 10))}
              </span>
              <span className="whitespace-pre-line">{message.body}</span>
            </li>
          ))}
        </ol>
        <form onSubmit={send} className="flex flex-col gap-2.5">
          <Field label={t('addMessage')} variant="form">
            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} maxLength={4000} className={`${controlClass(false, 'form')} resize-y`} />
          </Field>
          {error ? (
            <p role="alert" className="m-0 text-13 font-semibold text-red">
              {error}
            </p>
          ) : null}
          <button type="submit" disabled={busy || body.trim().length < 2} className={buttonClass('primary', 'md', 'self-start')}>
            {t('sendMessage')}
          </button>
        </form>
      </Card>
    </>
  );
}
