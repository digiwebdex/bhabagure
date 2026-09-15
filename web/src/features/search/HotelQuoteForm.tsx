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

const EMPTY = { location: '', checkIn: '', checkOut: '', category: '3', guests: '2', note: '', name: '', phone: '', email: '', company: '' };
type Values = typeof EMPTY;
const CATEGORIES = ['3', '4', '5'] as const;
const GUEST_OPTIONS = ['1', '2', '3', '4', '5', '6', '8', '10'] as const;

/** Nights between two YYYY-MM-DD dates, or null. */
function nightsBetween(checkIn: string, checkOut: string): number | null {
  if (!checkIn || !checkOut) return null;
  const nights = Math.round((Date.parse(`${checkOut}T00:00:00Z`) - Date.parse(`${checkIn}T00:00:00Z`)) / 86_400_000);
  return nights > 0 ? nights : null;
}

/**
 * Hotel quotation request (docs/phase-8-visa-quotes-pricing-downloads.md §4.B). The request reaches the Hotel requests
 * queue in the admin and the people on its alert list by WhatsApp and email; staff send the quotation back the same way.
 */
export function HotelQuoteForm() {
  const t = useTranslations('hotel');
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

  const nights = nightsBetween(values.checkIn, values.checkOut);
  const errors: Partial<Record<keyof Values, string>> = {};
  if (!values.location.trim()) errors.location = tv('required');
  if (!values.checkIn) errors.checkIn = tv('required');
  else if (values.checkIn < todayIso()) errors.checkIn = tv('dateFuture');
  if (!values.checkOut) errors.checkOut = tv('required');
  else if (values.checkIn && values.checkOut <= values.checkIn) errors.checkOut = t('checkOutBeforeCheckIn');
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
    const result = await submitPublicForm('hotel-quotes', {
      location: values.location.trim(),
      check_in: values.checkIn,
      check_out: values.checkOut,
      hotel_category: values.category,
      guests: values.guests,
      note: values.note.trim() || null,
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
    <form onSubmit={onSubmit} noValidate className={searchCardClass} aria-label={t('formLabel')}>
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
        <Field label={t('location')} error={show('location')}>
          <input value={values.location} onChange={(e) => set('location')(e.target.value)} placeholder={t('locationPh')} autoComplete="off" className={controlClass(!!show('location'))} />
        </Field>
        <Field label={t('checkIn')} error={show('checkIn')}>
          <input type="date" min={todayIso()} value={values.checkIn} onChange={(e) => set('checkIn')(e.target.value)} className={controlClass(!!show('checkIn'))} suppressHydrationWarning />
        </Field>
        <Field label={nights ? t('checkOutNights', { nights, nightsText: number(nights) }) : t('checkOut')} error={show('checkOut')}>
          <input type="date" min={values.checkIn || todayIso()} value={values.checkOut} onChange={(e) => set('checkOut')(e.target.value)} className={controlClass(!!show('checkOut'))} suppressHydrationWarning />
        </Field>
        <Field label={t('category')}>
          <select value={values.category} onChange={(e) => set('category')(e.target.value)} className={controlClass()}>
            {CATEGORIES.map((category) => (
              <option key={category} value={category}>
                {t(`categories.${category}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('guests')}>
          <select value={values.guests} onChange={(e) => set('guests')(e.target.value)} className={controlClass()}>
            {GUEST_OPTIONS.map((option) => {
              const count = Number.parseInt(option, 10);
              return (
                <option key={option} value={option}>
                  {t('guestOption', { count, countText: number(count) })}
                </option>
              );
            })}
          </select>
        </Field>
      </div>

      <Field label={t('note')}>
        <textarea value={values.note} onChange={(e) => set('note')(e.target.value)} placeholder={t('notePh')} rows={2} maxLength={1000} className={`${controlClass(false, 'form')} resize-y`} />
      </Field>

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
        <span className="max-w-44ch text-13 leading-1.55 text-muted">{t('explain')}</span>
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
