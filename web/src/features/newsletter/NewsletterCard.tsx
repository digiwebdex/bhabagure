'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState, type FormEvent } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { submitPublicForm } from '@/lib/forms';
import { displayPhone } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { isEmail } from '@/lib/validators';

/** Monthly newsletter sign-up on a navy card. */
export function NewsletterCard() {
  const t = useTranslations('newsletter');
  const tv = useTranslations('validation');
  const te = useTranslations('errors');
  const locale = useLocale();
  const { settings } = useSiteContent();
  const { digits } = useFormatters();
  const [email, setEmail] = useState('');
  const [company, setCompany] = useState('');
  const [attempted, setAttempted] = useState(false);
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');

  const error = !email.trim() ? tv('required') : !isEmail(email) ? tv('email') : null;

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (error || status === 'sending') return;
    if (company) {
      setStatus('sent');
      return;
    }
    setStatus('sending');
    const result = await submitPublicForm('newsletter', { email: email.trim(), locale });
    if (result.ok) {
      setEmail('');
      setAttempted(false);
      setStatus('sent');
    } else {
      setStatus('error');
    }
  };

  const showError = attempted && error;

  return (
    <div data-reveal className="grid-auto-fit-260 grid items-center gap-5 rounded-20 bg-ink-deep p-fluid-20-30 text-white">
      <div className="flex min-w-0 flex-col gap-1.5">
        <strong className="text-fluid-19-24 font-bold tracking-heading">{t('title')}</strong>
        <span className="text-14 leading-1.6 text-on-navy">{t('note')}</span>
      </div>
      <form onSubmit={onSubmit} noValidate className="flex min-w-0 flex-col gap-2.5">
        {status === 'sent' ? (
          <div role="status" className="rounded-12 border border-green-soft/40 bg-green-soft/16 px-3.5 py-3 text-13.5 font-semibold text-green-soft">
            {t('sent')}
          </div>
        ) : null}
        {status === 'error' ? (
          <div role="alert" className="rounded-12 border border-red-line/40 bg-red/20 px-3.5 py-3 text-13.5 font-semibold text-red-line">
            {te('sendFailed', { phone: digits(displayPhone(settings.contact.phone)) })}
          </div>
        ) : null}
        <div className="flex flex-wrap gap-2.25">
          <label className="flex min-w-37.5 flex-1 flex-col gap-1">
            <span className="sr-only">{t('placeholder')}</span>
            <input
              type="email"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                if (status !== 'sending') setStatus('idle');
              }}
              placeholder={t('placeholder')}
              autoComplete="email"
              aria-invalid={!!showError}
              className={`w-full rounded-pill border bg-white/8 px-4.5 py-3 text-14 text-white placeholder:text-on-navy outline-none focus-visible:ring-2 focus-visible:ring-white/40 ${
                showError ? 'border-red-line' : 'border-white/28'
              }`}
            />
            {showError ? (
              <span role="alert" className="px-2 text-12 font-semibold text-red-line">
                {error}
              </span>
            ) : null}
          </label>
          <input tabIndex={-1} autoComplete="off" aria-hidden value={company} onChange={(e) => setCompany(e.target.value)} name="company" className="sr-only" />
          <button type="submit" aria-disabled={status === 'sending'} className={buttonClass('cta', 'none', 'shrink-0 self-start px-6 py-3 text-14')}>
            {t('cta')}
          </button>
        </div>
      </form>
    </div>
  );
}
