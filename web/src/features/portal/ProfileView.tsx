'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { Field, controlClass } from '@/components/ui/Field';
import { buttonClass } from '@/components/ui/button';
import { portalCall } from '@/lib/customer-api';
import { displayPhone } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { isEmail, normalizeBdMobile, normalizeDigits } from '@/lib/validators';
import { useCustomerSession } from '@/state/customer-session';

import { usePortal } from './api';
import { Card, Heading, LoadState } from './ui';

type Profile = { name: string; phone: string; email: string | null; address: string | null; locale: 'bn' | 'en'; whatsappOptedOut: boolean };
type NpsPrompt = { prompt: { reference: string; title: string; travelEnd: string | null } | null };

/** Profile (§3.6) and the one NPS question after a completed trip (§0.4). Loyalty and referrals are deferred. */
export function ProfileView() {
  const [profile, reloadProfile, replaceProfile] = usePortal<Profile>('portal/profile');
  const [nps, reloadNps] = usePortal<NpsPrompt>('portal/nps');

  if (profile.state !== 'ready') return <LoadState state={profile.state} onRetry={reloadProfile} />;

  return (
    <div className="grid-auto-fit-280 grid items-start gap-4.5">
      <DetailsCard profile={profile.data} onSaved={replaceProfile} />
      <div className="flex flex-col gap-4.5">
        <IdentityCard profile={profile.data} onChanged={replaceProfile} />
        {nps.state === 'ready' && nps.data.prompt ? <NpsCard prompt={nps.data.prompt} onAnswered={reloadNps} /> : null}
      </div>
    </div>
  );
}

function DetailsCard({ profile, onSaved }: { profile: Profile; onSaved: (p: Profile) => void }) {
  const t = useTranslations('portal.profile');
  const locale = useLocale();
  const updateCustomer = useCustomerSession((state) => state.updateCustomer);
  const [form, setForm] = useState({ name: profile.name, address: profile.address ?? '', locale: profile.locale, whatsappOptedOut: profile.whatsappOptedOut });
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'failed'>('idle');
  const nameError = form.name.trim().length >= 2 ? undefined : t('nameRequired');

  const save = async (event: FormEvent) => {
    event.preventDefault();
    if (nameError) return setStatus('failed');
    setStatus('saving');
    const result = await portalCall<Profile>('portal/profile', locale, {
      method: 'PUT',
      body: JSON.stringify({ name: form.name.trim(), address: form.address.trim() || null, locale: form.locale, whatsapp_opted_out: form.whatsappOptedOut }),
    });
    if (!result.ok) return setStatus('failed');
    onSaved(result.data);
    const session = useCustomerSession.getState().customer;
    if (session) updateCustomer({ ...session, name: result.data.name, locale: result.data.locale });
    setStatus('saved');
  };

  return (
    <Card>
      <Heading as="h1" title={t('title')} />
      <form onSubmit={save} noValidate className="flex flex-col gap-3">
        <Field label={t('name')} error={status === 'failed' ? nameError : undefined} variant="form">
          <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="name" className={controlClass(status === 'failed' && !!nameError, 'form')} />
        </Field>
        <Field label={t('address')} variant="form">
          <textarea value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} rows={2} maxLength={300} autoComplete="street-address" className={`${controlClass(false, 'form')} resize-y`} />
        </Field>
        <Field label={t('messageLanguage')} variant="form">
          <select value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value as 'bn' | 'en' })} className={controlClass(false, 'form')}>
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </Field>
        <label className="flex cursor-pointer items-start gap-2.5 text-14">
          <input type="checkbox" checked={!form.whatsappOptedOut} onChange={(e) => setForm({ ...form, whatsappOptedOut: !e.target.checked })} className="mt-1 accent-blue" />
          <span className="flex flex-col gap-0.5">
            <span className="font-semibold">{t('whatsapp')}</span>
            <span className="text-12 text-app-muted">{t('whatsappNote')}</span>
          </span>
        </label>
        {status === 'saved' ? (
          <p role="status" className="m-0 text-13 font-semibold text-green">
            {t('saved')}
          </p>
        ) : status === 'failed' && !nameError ? (
          <p role="alert" className="m-0 text-13 font-semibold text-red">
            {t('saveFailed')}
          </p>
        ) : null}
        <button type="submit" disabled={status === 'saving'} className={buttonClass('primary', 'block')}>
          {t('save')}
        </button>
      </form>
    </Card>
  );
}

type ChangeKind = 'phone' | 'email';

/** Mobile number and email: each changes only with a code sent to the new one. */
function IdentityCard({ profile, onChanged }: { profile: Profile; onChanged: (p: Profile) => void }) {
  const t = useTranslations('portal.profile');
  const f = useFormatters();
  const [changing, setChanging] = useState<ChangeKind | null>(null);
  const finish = (changed: Profile | null) => {
    if (changed) onChanged(changed);
    setChanging(null);
  };

  return (
    <Card>
      <Heading title={t('signInDetails')} />
      <div className="flex flex-col gap-3 text-14">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="flex flex-col">
            <span className="text-12 text-app-muted">{t('phone')}</span>
            <span className="font-semibold">{f.digits(displayPhone(profile.phone))}</span>
          </span>
          {changing !== 'phone' ? (
            <button type="button" onClick={() => setChanging('phone')} className="cursor-pointer text-13 font-semibold text-blue hover:text-orange">
              {t('change')}
            </button>
          ) : null}
        </div>
        {changing === 'phone' ? <ChangeForm kind="phone" onDone={finish} /> : null}
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="flex flex-col">
            <span className="text-12 text-app-muted">{t('email')}</span>
            <span className="font-semibold">{profile.email ?? t('noEmail')}</span>
          </span>
          {changing !== 'email' ? (
            <button type="button" onClick={() => setChanging('email')} className="cursor-pointer text-13 font-semibold text-blue hover:text-orange">
              {profile.email ? t('change') : t('addEmail')}
            </button>
          ) : null}
        </div>
        {changing === 'email' ? <ChangeForm kind="email" onDone={finish} /> : null}
      </div>
    </Card>
  );
}

function ChangeForm({ kind, onDone }: { kind: ChangeKind; onDone: (profile: Profile | null) => void }) {
  const t = useTranslations('portal.profile');
  const tv = useTranslations('validation');
  const locale = useLocale();
  const [value, setValue] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState<'enter' | 'code'>('enter');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const normalized = kind === 'phone' ? normalizeBdMobile(value) : isEmail(value) ? value.trim() : null;

  const request = async (event: FormEvent) => {
    event.preventDefault();
    if (!normalized) return setError(kind === 'phone' ? tv('phone') : tv('email'));
    setBusy(true);
    setError(null);
    const result = await portalCall(kind === 'phone' ? 'portal/profile/phone/code' : 'portal/profile/email', locale, {
      method: 'POST',
      body: JSON.stringify(kind === 'phone' ? { phone: normalized } : { email: normalized }),
    });
    setBusy(false);
    if (result.ok) return setStep('code');
    setError(result.message ?? t('codeFailed'));
  };

  const confirm = async (event: FormEvent) => {
    event.preventDefault();
    const digits = normalizeDigits(code).replace(/\s/g, '');
    if (!/^\d{6}$/.test(digits)) return setError(tv('code'));
    setBusy(true);
    setError(null);
    const result = await portalCall<Profile>(kind === 'phone' ? 'portal/profile/phone' : 'portal/profile/email/confirm', locale, {
      method: 'POST',
      body: JSON.stringify(kind === 'phone' ? { phone: normalized, code: digits } : { code: digits }),
    });
    setBusy(false);
    if (result.ok) return onDone(result.data);
    setError(result.message ?? t('codeFailed'));
  };

  return (
    <form onSubmit={step === 'enter' ? request : confirm} noValidate className="flex flex-col gap-2.5 rounded-12 bg-app-surface-2 p-3">
      {step === 'enter' ? (
        <Field label={kind === 'phone' ? t('newPhone') : t('newEmail')}>
          <input
            value={value}
            onChange={(e) => setValue(e.target.value)}
            type={kind === 'phone' ? 'tel' : 'email'}
            autoComplete={kind === 'phone' ? 'tel' : 'email'}
            placeholder={kind === 'phone' ? '01XXXXXXXXX' : 'you@email.com'}
            className={controlClass(!!error)}
          />
        </Field>
      ) : (
        <>
          <span className="text-13">{kind === 'phone' ? t('codeSentPhone') : t('codeSentEmail', { email: normalized ?? '' })}</span>
          <Field label={t('code')}>
            <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" maxLength={6} className={`${controlClass(!!error)} font-display tracking-[0.3em]`} />
          </Field>
        </>
      )}
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <button type="submit" disabled={busy} className={buttonClass('primary', 'sm')}>
          {step === 'enter' ? t('sendCode') : t('confirm')}
        </button>
        <button type="button" onClick={() => onDone(null)} className={buttonClass('outlineInk', 'sm')}>
          {t('cancel')}
        </button>
      </div>
    </form>
  );
}

function NpsCard({ prompt, onAnswered }: { prompt: NonNullable<NpsPrompt['prompt']>; onAnswered: () => void }) {
  const t = useTranslations('portal.nps');
  const f = useFormatters();
  const locale = useLocale();
  const [score, setScore] = useState<number | null>(null);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ score: number; reviewUrl: string | null; followUp: boolean } | null>(null);
  const [error, setError] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (score === null || busy) return;
    setBusy(true);
    setError(false);
    const response = await portalCall<{ score: number; reviewUrl: string | null; followUp: boolean }>(`portal/trips/${encodeURIComponent(prompt.reference)}/nps`, locale, {
      method: 'POST',
      body: JSON.stringify({ score, comment: comment.trim() || null }),
    });
    setBusy(false);
    if (response.ok) setResult(response.data);
    else if (response.reason === 'conflict') onAnswered();
    else setError(true);
  };

  if (result) {
    return (
      <Card>
        <Heading title={t('title')} />
        <p role="status" className="m-0 text-14 leading-1.6">
          {result.score >= 9 ? t('promoter') : result.score >= 7 ? t('passive') : t('detractor')}
        </p>
        {result.reviewUrl ? (
          <a href={result.reviewUrl} target="_blank" rel="noopener noreferrer" className={buttonClass('primary', 'sm', 'self-start')}>
            {t('review')}
          </a>
        ) : null}
      </Card>
    );
  }

  return (
    <Card>
      <Heading title={t('title')} />
      <form onSubmit={submit} className="flex flex-col gap-2.75">
        <span className="text-13.5 leading-1.55">{t('question', { trip: prompt.title })}</span>
        <div role="radiogroup" aria-label={t('scale')} className="flex flex-wrap gap-1.25">
          {Array.from({ length: 11 }, (_, n) => (
            <button
              key={n}
              type="button"
              role="radio"
              aria-checked={score === n}
              onClick={() => setScore(n)}
              className={`size-8.5 cursor-pointer rounded-9 border-chip font-display text-13 font-bold ${score === n ? 'border-blue bg-blue text-white' : 'border-portal-line bg-app-surface text-app-text'}`}
            >
              {f.number(n)}
            </button>
          ))}
        </div>
        <span className="text-12 text-app-muted">{t('scaleNote')}</span>
        {score !== null ? (
          <Field label={t('comment')}>
            <textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={2} maxLength={1000} className={`${controlClass(false)} h-auto resize-y py-2`} />
          </Field>
        ) : null}
        {error ? (
          <span role="alert" className="text-12 font-semibold text-red">
            {t('failed')}
          </span>
        ) : null}
        <button type="submit" disabled={score === null || busy} className={buttonClass('primary', 'sm', 'self-start')}>
          {t('send')}
        </button>
      </form>
    </Card>
  );
}
