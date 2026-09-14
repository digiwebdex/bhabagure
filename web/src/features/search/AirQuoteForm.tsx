'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { controlClass, Field } from '@/components/ui/Field';
import { submitPublicForm } from '@/lib/forms';
import { displayPhone } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { isEmail, normalizeBdMobile, todayIso } from '@/lib/validators';

import { searchCardClass } from './styles';

const EMPTY = { from: '', to: '', depart: '', ret: '', passengers: '2', cabin: 'economy', name: '', phone: '', email: '', company: '' };
type Values = typeof EMPTY;
const PASSENGER_OPTIONS = ['1', '2', '3', '4', '5', '6+'] as const;

/** Air-ticket quote request. Submitting notifies the ticketing team (email now, WhatsApp in Phase 4). */
export function AirQuoteForm() {
  const t = useTranslations('air');
  const tv = useTranslations('validation');
  const te = useTranslations('errors');
  const locale = useLocale();
  const { settings } = useSiteContent();
  const { number, digits } = useFormatters();

  const [values, setValues] = useState<Values>(EMPTY);
  const [attempted, setAttempted] = useState(false);
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent' | 'error' | 'rate_limited'>('idle');

  const set = (key: keyof Values) => (value: string) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    if (status === 'sent' || status === 'error') setStatus('idle');
  };

  const errors: Partial<Record<keyof Values, string>> = {};
  if (!values.from.trim()) errors.from = tv('required');
  if (!values.to.trim()) errors.to = tv('required');
  if (!values.depart) errors.depart = tv('required');
  else if (values.depart < todayIso()) errors.depart = tv('dateFuture');
  if (values.ret && values.depart && values.ret < values.depart) errors.ret = t('returnBeforeDepart');
  if (!values.name.trim()) errors.name = tv('required');
  if (!values.phone.trim()) errors.phone = tv('required');
  else if (!normalizeBdMobile(values.phone)) errors.phone = tv('phone');
  if (values.email.trim() && !isEmail(values.email)) errors.email = tv('email');
  const invalid = Object.keys(errors).length > 0;
  const show = (key: keyof Values) => (attempted ? errors[key] : undefined);

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (invalid || status === 'sending') return;
    if (values.company) {
      // Honeypot filled: a bot. Act as if it worked; send nothing.
      setStatus('sent');
      return;
    }
    setStatus('sending');
    const result = await submitPublicForm('air-quotes', {
      from_place: values.from.trim(),
      to_place: values.to.trim(),
      depart_on: values.depart,
      return_on: values.ret || null,
      passengers: values.passengers,
      cabin_class: values.cabin,
      name: values.name.trim(),
      phone: normalizeBdMobile(values.phone),
      email: values.email.trim() || null,
      locale,
    });
    if (result.ok) {
      setValues(EMPTY);
      setAttempted(false);
      setStatus('sent');
    } else {
      setStatus(result.reason === 'rate_limited' ? 'rate_limited' : 'error');
    }
  };

  const hours = settings.hours;
  return (
    <form onSubmit={onSubmit} noValidate className={searchCardClass}>
      {status === 'sent' ? (
        <div role="status" className="flex flex-col gap-1.25 rounded-14 border border-green-line bg-green-tint p-4 text-green-deep">
          <strong className="text-15 font-bold">{t('sentTitle')}</strong>
          <span className="text-13.5 leading-1.6">{t('sentNote', { open: number(hours.opens), close: number(hours.closes - 12) })}</span>
        </div>
      ) : null}
      {status === 'error' || status === 'rate_limited' ? (
        <div role="alert" className="rounded-11 border border-red-line bg-red-tint px-3.25 py-2.75 text-13 font-semibold leading-1.5 text-red">
          {status === 'rate_limited' ? te('tooMany') : te('sendFailed', { phone: digits(displayPhone(settings.contact.phone)) })}
        </div>
      ) : null}

      <div className="grid-auto-fit-158 grid gap-3">
        <Field label={t('from')} error={show('from')}>
          <input value={values.from} onChange={(e) => set('from')(e.target.value)} placeholder={t('fromPh')} autoComplete="off" className={controlClass(!!show('from'))} />
        </Field>
        <Field label={t('to')} error={show('to')}>
          <input value={values.to} onChange={(e) => set('to')(e.target.value)} placeholder={t('toPh')} autoComplete="off" className={controlClass(!!show('to'))} />
        </Field>
        <Field label={t('depart')} error={show('depart')}>
          <input type="date" min={todayIso()} value={values.depart} onChange={(e) => set('depart')(e.target.value)} className={controlClass(!!show('depart'))} suppressHydrationWarning />
        </Field>
        <Field label={t('return')} error={show('ret')}>
          <input type="date" min={values.depart || todayIso()} value={values.ret} onChange={(e) => set('ret')(e.target.value)} className={controlClass(!!show('ret'))} suppressHydrationWarning />
        </Field>
        <Field label={t('passengers')}>
          <select value={values.passengers} onChange={(e) => set('passengers')(e.target.value)} className={controlClass()}>
            {PASSENGER_OPTIONS.map((option) => {
              const count = Number.parseInt(option, 10);
              return (
                <option key={option} value={option}>
                  {option.endsWith('+')
                    ? t('passengerOptionMax', { countText: number(count) })
                    : t('passengerOption', { count, countText: number(count) })}
                </option>
              );
            })}
          </select>
        </Field>
        <Field label={t('class')}>
          <select value={values.cabin} onChange={(e) => set('cabin')(e.target.value)} className={controlClass()}>
            <option value="economy">{t('economy')}</option>
            <option value="premium">{t('premium')}</option>
            <option value="business">{t('business')}</option>
          </select>
        </Field>
      </div>

      <div className="grid-auto-fit-200 grid gap-3">
        <Field label={<span className="sr-only">{t('name')}</span>} error={show('name')}>
          <input value={values.name} onChange={(e) => set('name')(e.target.value)} placeholder={t('name')} autoComplete="name" className={controlClass(!!show('name'))} />
        </Field>
        <Field label={<span className="sr-only">{t('phone')}</span>} error={show('phone')}>
          <input value={values.phone} onChange={(e) => set('phone')(e.target.value)} placeholder={t('phone')} inputMode="tel" autoComplete="tel" className={controlClass(!!show('phone'))} />
        </Field>
        <Field label={<span className="sr-only">{t('email')}</span>} error={show('email')}>
          <input type="email" value={values.email} onChange={(e) => set('email')(e.target.value)} placeholder={t('email')} autoComplete="email" className={controlClass(!!show('email'))} />
        </Field>
      </div>

      {/* Honeypot: hidden from people, tempting to bots. */}
      <input tabIndex={-1} autoComplete="off" aria-hidden value={values.company} onChange={(e) => set('company')(e.target.value)} name="company" className="sr-only" />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <span className="max-w-44ch text-13 leading-1.55 text-muted">{t('note')}</span>
        <button
          type="submit"
          aria-disabled={status === 'sending'}
          className={buttonClass('primary', 'none', `px-6.5 py-3 text-14 ${attempted && invalid ? 'cursor-not-allowed opacity-50' : ''}`)}
        >
          {t('submit')}
        </button>
      </div>
    </form>
  );
}
