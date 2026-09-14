'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useEffect, useState, type FormEvent } from 'react';

import { WhatsAppGlyph } from '@/components/brand/WhatsAppGlyph';
import { Field, controlClass } from '@/components/ui/Field';
import { buttonClass } from '@/components/ui/button';
import { sendCode, verifyCode } from '@/lib/customer-api';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeBdMobile, normalizeDigits } from '@/lib/validators';
import type { SessionCustomer } from '@/state/customer-session';

/** Matches LoginCodes::MINUTES in the API. */
const CODE_MINUTES = 10;

type Step = 'phone' | 'code' | 'name';
type Problem = 'invalidCode' | 'throttled' | 'undeliverable' | 'portalDisabled' | 'tooMany' | 'unavailable' | null;

/**
 * Phone → one-time code → signed in (docs/phase-6-customer-portal.md §3.1). A number the API has no record for is asked
 * for a name after the code is proven; nothing before that says whether the number belongs to a customer.
 * Used by the website's sign-in dialog and the portal's sign-in screen.
 */
export function CodeSignIn({ onSignedIn, whatsapp }: { onSignedIn: (customer: SessionCustomer) => void; whatsapp: string }) {
  const t = useTranslations('auth');
  const tv = useTranslations('validation');
  const locale = useLocale();
  const f = useFormatters();

  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<Problem>(null);
  const [retrySeconds, setRetrySeconds] = useState(0);
  const [waitSeconds, setWaitSeconds] = useState(0);

  // The resend countdown: ticks only while a wait is running.
  useEffect(() => {
    if (waitSeconds <= 0) return;
    const timer = setTimeout(() => setWaitSeconds((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [waitSeconds]);

  const normalizedPhone = normalizeBdMobile(phone);
  const normalizedCode = normalizeDigits(code).replace(/\s/g, '');
  const errors = {
    phone: !phone.trim() ? tv('required') : !normalizedPhone ? tv('phone') : undefined,
    code: /^\d{6}$/.test(normalizedCode) ? undefined : tv('code'),
    name: name.trim().length >= 2 ? undefined : tv('required'),
  };

  const requestCode = async () => {
    if (!normalizedPhone || busy) return;
    setBusy(true);
    setProblem(null);
    const result = await sendCode(normalizedPhone, locale);
    setBusy(false);
    if (result.ok) {
      setStep('code');
      setCode('');
      setAttempted(false);
      setWaitSeconds(result.retryAfter);
      return;
    }
    if (result.reason === 'throttled') {
      // A code already went out a moment ago: the customer can type that one.
      setRetrySeconds(result.retryAfter);
      setWaitSeconds(result.retryAfter);
      setStep('code');
      return setProblem('throttled');
    }
    setProblem(result.reason === 'undeliverable' ? 'undeliverable' : result.reason === 'rate_limited' ? 'tooMany' : 'unavailable');
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (step === 'phone') {
      if (errors.phone) return;
      return requestCode();
    }
    if (errors.code || (step === 'name' && errors.name) || busy || !normalizedPhone) return;
    setBusy(true);
    setProblem(null);
    const result = await verifyCode({ phone: normalizedPhone, code: normalizedCode, ...(step === 'name' ? { name: name.trim() } : {}) }, locale);
    setBusy(false);
    if (result.ok) return onSignedIn(result.customer);
    if (result.reason === 'name_required') {
      setAttempted(false);
      return setStep('name');
    }
    setProblem(
      result.reason === 'invalid_code' ? 'invalidCode' : result.reason === 'portal_disabled' ? 'portalDisabled' : result.reason === 'rate_limited' ? 'tooMany' : 'unavailable',
    );
  };

  const show = (key: keyof typeof errors) => (attempted ? errors[key] : undefined);
  const problemText = problem
    ? problem === 'throttled'
      ? t('throttled', { secondsText: f.number(retrySeconds) })
      : t(problem)
    : null;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
      {step === 'phone' ? (
        <Field label={t('mobile')} error={show('phone')} variant="form">
          <input
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            placeholder="01XXXXXXXXX"
            aria-invalid={!!show('phone')}
            className={controlClass(!!show('phone'), 'form')}
          />
        </Field>
      ) : (
        <>
          <p className="rounded-11 bg-paper-alt px-3.25 py-2.75 text-13.5 leading-1.55 text-ink">
            {t('codeSent', { phone: f.digits(`0${normalizedPhone?.slice(3) ?? ''}`), minutesText: f.number(CODE_MINUTES) })}
          </p>
          <Field label={t('code')} error={show('code')} variant="form">
            <input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              placeholder="••••••"
              aria-invalid={!!show('code')}
              readOnly={step === 'name'}
              className={`${controlClass(!!show('code'), 'form')} font-display tracking-[0.3em]`}
            />
          </Field>
          {step === 'name' ? (
            <>
              <p className="text-13.5 leading-1.55 font-semibold text-ink">{t('nameNeeded')}</p>
              <Field label={t('fullName')} error={show('name')} variant="form">
                <input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  autoComplete="name"
                  placeholder={t('fullNamePh')}
                  aria-invalid={!!show('name')}
                  className={controlClass(!!show('name'), 'form')}
                />
              </Field>
            </>
          ) : null}
        </>
      )}

      {problemText ? (
        <div
          role="alert"
          className={`flex flex-col gap-2.5 rounded-11 border px-3.25 py-2.75 text-13 leading-1.5 font-semibold ${
            problem === 'throttled' ? 'border-orange-line bg-orange-wash text-ink' : 'border-red-line bg-red-tint text-red'
          }`}
        >
          {problemText}
          {problem === 'undeliverable' || problem === 'portalDisabled' ? (
            <a href={whatsappUrl(whatsapp, t('contactUsMessage'))} target="_blank" rel="noopener noreferrer" className={buttonClass('success', 'block')}>
              <WhatsAppGlyph className="size-4.5" />
              {t('contactUsWhatsapp')}
            </a>
          ) : null}
        </div>
      ) : null}

      <button type="submit" aria-disabled={busy} className={buttonClass('cta', 'block', `mt-0.5 ${busy ? 'cursor-progress opacity-70' : ''}`)}>
        {step === 'phone' ? t('sendCode') : step === 'name' ? t('finishCta') : t('verifyCta')}
      </button>

      {step !== 'phone' ? (
        <div className="flex flex-wrap items-center justify-between gap-2 text-13 font-semibold">
          <button
            type="button"
            onClick={() => {
              setStep('phone');
              setProblem(null);
              setAttempted(false);
            }}
            className="cursor-pointer text-blue hover:text-orange"
          >
            {t('changeNumber')}
          </button>
          <button
            type="button"
            onClick={() => void requestCode()}
            disabled={waitSeconds > 0 || busy}
            className="cursor-pointer text-blue hover:text-orange disabled:cursor-not-allowed disabled:text-muted"
          >
            {waitSeconds > 0 ? t('resendIn', { secondsText: f.number(waitSeconds) }) : t('resend')}
          </button>
        </div>
      ) : null}
      <span className="text-center text-12 leading-1.6 text-muted-label">{t('legal')}</span>
    </form>
  );
}
