'use client';

import { useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { WhatsAppGlyph } from '@/components/brand/WhatsAppGlyph';
import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Modal, ModalClose } from '@/components/ui/Modal';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { isEmail, normalizeBdMobile } from '@/lib/validators';
import { useCustomerSession } from '@/state/customer-session';
import { useSiteUi } from '@/state/site-ui';

import { signInCustomer, registerCustomer } from './customer-auth';

/** Decided 2026-09-13: 8+ characters — the portal holds passport numbers. Same value as the API (CustomerAuthController). */
const MIN_PASSWORD = 8;

type Status = 'idle' | 'sending' | 'done' | 'error' | 'invalid' | 'unavailable' | 'contact_phone' | 'contact_email';

/** Sign in / Register. On success the header pill becomes the customer's initials, linking to the portal. */
export function AuthModal() {
  const t = useTranslations('auth');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const { number } = useFormatters();
  const open = useSiteUi((state) => state.authOpen);
  const tab = useSiteUi((state) => state.authTab);
  const setTab = useSiteUi((state) => state.setAuthTab);
  const close = useSiteUi((state) => state.closeAuth);
  const signIn = useCustomerSession((state) => state.signIn);
  const { settings } = useSiteContent();

  const [values, setValues] = useState({ identifier: '', password: '', name: '', phone: '', email: '', newPassword: '' });
  const [attempted, setAttempted] = useState(false);
  const [status, setStatus] = useState<Status>('idle');
  const isSignIn = tab === 'signIn';

  const set = (key: keyof typeof values) => (value: string) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    if (status !== 'idle' && status !== 'sending' && status !== 'done') setStatus('idle');
  };

  const errors: Partial<Record<keyof typeof values, string>> = {};
  if (isSignIn) {
    const id = values.identifier.trim();
    if (!id) errors.identifier = tv('identifier');
    else if (!isEmail(id) && !normalizeBdMobile(id)) errors.identifier = tv('identifier');
    if (!values.password) errors.password = tv('required');
  } else {
    if (!values.name.trim()) errors.name = tv('required');
    if (!values.phone.trim()) errors.phone = tv('required');
    else if (!normalizeBdMobile(values.phone)) errors.phone = tv('phone');
    if (!values.email.trim()) errors.email = tv('required');
    else if (!isEmail(values.email)) errors.email = tv('email');
    if (values.newPassword.length < MIN_PASSWORD) errors.newPassword = tv('minLength', { minText: number(MIN_PASSWORD) });
  }
  const invalid = Object.keys(errors).length > 0;
  const show = (key: keyof typeof values) => (attempted ? errors[key] : undefined);

  const switchTab = (next: typeof tab) => {
    setTab(next);
    setAttempted(false);
    setStatus('idle');
  };

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (invalid || status === 'sending') return;
    setStatus('sending');
    const result = isSignIn
      ? await signInCustomer({ identifier: values.identifier.trim(), password: values.password })
      : await registerCustomer({ name: values.name.trim(), phone: normalizeBdMobile(values.phone)!, email: values.email.trim(), password: values.newPassword });
    if (result.ok) {
      signIn({ name: result.name });
      setValues({ identifier: '', password: '', name: '', phone: '', email: '', newPassword: '' });
      setAttempted(false);
      setStatus('done');
    } else {
      setStatus(
        result.reason === 'contact_us'
          ? result.field === 'email' ? 'contact_email' : 'contact_phone'
          : result.reason === 'invalid_credentials' ? 'invalid' : result.reason === 'unavailable' ? 'unavailable' : 'error',
      );
    }
  };

  const input = (key: keyof typeof values, label: string, props: Record<string, string> = {}) => (
    <label className="flex flex-col gap-1.5 text-12.5 font-semibold text-muted">
      {label}
      <input
        value={values[key]}
        onChange={(e) => set(key)(e.target.value)}
        aria-invalid={!!show(key)}
        className={`rounded-11 border bg-white p-3 font-sans text-14 font-normal text-ink outline-none focus-visible:border-blue focus-visible:ring-2 focus-visible:ring-blue/20 ${show(key) ? 'border-red' : 'border-input'}`}
        {...props}
      />
      {show(key) ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {show(key)}
        </span>
      ) : null}
    </label>
  );

  const tabButton = (value: typeof tab, label: string) => {
    const active = tab === value;
    return (
      <button
        type="button"
        role="tab"
        aria-selected={active}
        onClick={() => switchTab(value)}
        className={`flex-1 cursor-pointer rounded-11 border-chip p-2.5 text-14 font-semibold ${active ? 'border-blue bg-blue-tint text-blue-deep' : 'border-input bg-white text-muted'}`}
      >
        {label}
      </button>
    );
  };

  const portalUrl = process.env.NEXT_PUBLIC_PORTAL_URL ?? '/';

  return (
    <Modal open={open} onClose={close} labelledBy="auth-title" size="sm" layer="auth">
      <div className="overflow-y-auto">
        <div className="flex items-start justify-between gap-3.5 px-fluid-18-26 pt-5">
          <div className="flex min-w-0 flex-col gap-1">
            <h2 id="auth-title" className="text-fluid-19-23 font-bold tracking-title">
              {isSignIn ? t('signInTitle') : t('registerTitle')}
            </h2>
            <span className="text-13.5 leading-1.55 text-muted">{isSignIn ? t('signInSubtitle') : t('registerSubtitle')}</span>
          </div>
          <ModalClose onClick={close} label={tc('close')} size="sm" />
        </div>
        <div role="tablist" className="flex gap-1.5 px-fluid-18-26 pt-4">
          {tabButton('signIn', t('tabSignIn'))}
          {tabButton('register', t('tabRegister'))}
        </div>

        {status === 'done' ? (
          <div className="flex flex-col gap-3.5 px-fluid-18-26 pt-4.5 pb-5.5">
            <div role="status" className="flex flex-col gap-1.25 rounded-14 border border-green-line bg-green-tint p-4 text-green-deep">
              <strong className="text-15 font-bold">{isSignIn ? t('signedInTitle') : t('registeredTitle')}</strong>
              <span className="text-13.5 leading-1.6">{isSignIn ? t('signedInNote') : t('registeredNote')}</span>
            </div>
            <a href={portalUrl} className={buttonClass('cta', 'block')}>
              {t('goPortal')}
            </a>
          </div>
        ) : (
          <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3 px-fluid-18-26 pt-4 pb-5.5">
            {isSignIn ? (
              <>
                {input('identifier', t('identifier'), { placeholder: '01XXXXXXXXX', autoComplete: 'username' })}
                {input('password', t('password'), { type: 'password', placeholder: '••••••••', autoComplete: 'current-password' })}
              </>
            ) : (
              <>
                {input('name', t('fullName'), { placeholder: t('fullNamePh'), autoComplete: 'name' })}
                {input('phone', t('mobile'), { type: 'tel', placeholder: '01XXXXXXXXX', autoComplete: 'tel' })}
                {input('email', t('email'), { type: 'email', placeholder: 'you@email.com', autoComplete: 'email' })}
                {input('newPassword', t('newPassword', { minText: number(MIN_PASSWORD) }), { type: 'password', placeholder: '••••••••', autoComplete: 'new-password' })}
              </>
            )}
            {status === 'contact_phone' || status === 'contact_email' ? (
              <div role="alert" className="flex flex-col gap-2.5 rounded-11 border border-orange-line bg-orange-wash px-3.25 py-2.75 text-13 leading-1.5 font-semibold text-ink">
                {status === 'contact_email' ? t('contactUsEmail') : t('contactUs')}
                <a
                  href={whatsappUrl(settings.contact.whatsapp, t('contactUsMessage'))}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={buttonClass('success', 'block')}
                >
                  <WhatsAppGlyph className="size-4.5" />
                  {t('contactUsWhatsapp')}
                </a>
              </div>
            ) : null}
            {status === 'invalid' || status === 'error' || status === 'unavailable' ? (
              <div role="alert" className="rounded-11 border border-red-line bg-red-tint px-3.25 py-2.75 text-13 leading-1.5 font-semibold text-red">
                {status === 'invalid' ? t('invalidCredentials') : t('unavailable')}
              </div>
            ) : null}
            <button
              type="submit"
              aria-disabled={status === 'sending'}
              className={buttonClass('cta', 'block', `mt-0.5 ${attempted && invalid ? 'cursor-not-allowed opacity-50' : ''}`)}
            >
              {isSignIn ? t('signInCta') : t('registerCta')}
            </button>
            <span className="text-center text-12 leading-1.6 text-muted-label">{t('legal')}</span>
          </form>
        )}
      </div>
    </Modal>
  );
}
