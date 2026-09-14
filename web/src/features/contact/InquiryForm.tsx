'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { clampTravellers, perPersonRate } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { controlClass, Field } from '@/components/ui/Field';
import { submitPublicForm } from '@/lib/forms';
import { displayPhone } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeBdMobile, normalizeDigits } from '@/lib/validators';
import { useTripSearch } from '@/state/trip-search';

/** Name, phone, package and travellers. Staff follow up by phone. */
export function InquiryForm() {
  const t = useTranslations('contact');
  const tv = useTranslations('validation');
  const te = useTranslations('errors');
  const locale = useLocale();
  const { packages, pricing, settings } = useSiteContent();
  const f = useFormatters();
  const searchPax = useTripSearch((state) => state.pax);

  const [values, setValues] = useState({ name: '', phone: '', packageSlug: '', travellers: '2', company: '' });
  const [attempted, setAttempted] = useState(false);
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const set = (key: keyof typeof values) => (value: string) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    if (status === 'sent' || status === 'error') setStatus('idle');
  };

  const travellers = Number(normalizeDigits(values.travellers));
  const errors = {
    name: !values.name.trim() ? tv('required') : null,
    phone: !values.phone.trim() ? tv('required') : !normalizeBdMobile(values.phone) ? tv('phone') : null,
    travellers:
      !Number.isInteger(travellers) || travellers < 1 || travellers > pricing.maxTravellers
        ? tv('travellers', { maxText: f.number(pricing.maxTravellers) })
        : null,
  };
  const invalid = Object.values(errors).some(Boolean);
  const show = (key: keyof typeof errors) => (attempted ? errors[key] : null);

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (invalid || status === 'sending') return;
    if (values.company) {
      setStatus('sent');
      return;
    }
    setStatus('sending');
    const result = await submitPublicForm('inquiries', {
      name: values.name.trim(),
      phone: normalizeBdMobile(values.phone),
      package_slug: values.packageSlug || null,
      travellers: clampTravellers(travellers, pricing.maxTravellers),
      locale,
    });
    if (result.ok) {
      setValues({ name: '', phone: '', packageSlug: '', travellers: '2', company: '' });
      setAttempted(false);
      setStatus('sent');
    } else {
      setStatus('error');
    }
  };

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3.5 rounded-20 bg-white p-fluid-18-26 text-ink-deep">
      <h3 className="text-22 font-semibold">
        {t('formTitle')}{' '}
        {t('formTitleSub') ? <span className="font-display text-16 font-normal text-muted">{t('formTitleSub')}</span> : null}
      </h3>
      {status === 'error' ? (
        <div role="alert" className="rounded-11 border border-red-line bg-red-tint px-3.25 py-2.75 text-13 font-semibold leading-1.5 text-red">
          {te('sendFailed', { phone: f.digits(displayPhone(settings.contact.phone)) })}
        </div>
      ) : null}
      <Field variant="form" label={t('name')} error={show('name')}>
        <input value={values.name} onChange={(e) => set('name')(e.target.value)} autoComplete="name" className={controlClass(!!show('name'), 'form')} />
      </Field>
      <Field variant="form" label={t('phoneField')} error={show('phone')}>
        <input value={values.phone} onChange={(e) => set('phone')(e.target.value)} inputMode="tel" autoComplete="tel" className={controlClass(!!show('phone'), 'form')} />
      </Field>
      <Field variant="form" label={t('package')}>
        <select value={values.packageSlug} onChange={(e) => set('packageSlug')(e.target.value)} className={controlClass(false, 'form')}>
          <option value="">{t('choosePackage')}</option>
          {packages.map((p) => (
            <option key={p.slug} value={p.slug}>
              {t('packageOption', { title: p.title, price: f.bdt(perPersonRate(p.listPrice, searchPax, pricing.slabs)) })}
            </option>
          ))}
        </select>
      </Field>
      <Field variant="form" label={t('travellers')} error={show('travellers')}>
        <input
          type="number"
          min={1}
          max={pricing.maxTravellers}
          value={values.travellers}
          onChange={(e) => set('travellers')(e.target.value)}
          className={controlClass(!!show('travellers'), 'form')}
        />
      </Field>
      <input tabIndex={-1} autoComplete="off" aria-hidden value={values.company} onChange={(e) => set('company')(e.target.value)} name="company" className="sr-only" />
      <button
        type="submit"
        aria-disabled={status === 'sending'}
        className={`mt-1.5 cursor-pointer rounded-pill bg-linear-135/srgb from-orange-bright to-orange-deep p-3.5 font-sans text-16 font-semibold text-white shadow-glow-orange transition hover:from-orange-press hover:to-orange-press ${
          attempted && invalid ? 'cursor-not-allowed opacity-50' : ''
        }`}
      >
        {status === 'sent' ? t('sent') : t('submit')}
      </button>
      <p className="text-center text-12 text-muted">{t('paymentNote')}</p>
    </form>
  );
}
