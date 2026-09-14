'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { whatsappUrl } from '@/lib/links';

type State = 'loading' | 'subscribed' | 'sending' | 'unsubscribed' | 'invalid' | 'unavailable';

export function UnsubscribePanel({ token }: { token: string }) {
  const t = useTranslations('unsubscribe');
  const { settings } = useSiteContent();
  const base = process.env.NEXT_PUBLIC_API_URL;
  const [state, setState] = useState<State>(base ? 'loading' : 'unavailable');
  const [email, setEmail] = useState('');
  const url = `${base}/api/v1/public/newsletter/unsubscribe/${encodeURIComponent(token)}`;

  useEffect(() => {
    if (!base) return;
    let cancelled = false;
    fetch(url, { headers: { Accept: 'application/json' } })
      .then(async (res) => {
        if (cancelled) return;
        if (res.status === 403 || res.status === 404) return setState('invalid');
        if (!res.ok) return setState('unavailable');
        const body = (await res.json()) as { data: { email: string; status: string } };
        setEmail(body.data.email);
        setState(body.data.status === 'unsubscribed' ? 'unsubscribed' : 'subscribed');
      })
      .catch(() => !cancelled && setState('unavailable'));
    return () => {
      cancelled = true;
    };
  }, [base, url]);

  const unsubscribe = async () => {
    setState('sending');
    try {
      const res = await fetch(url, { method: 'POST', headers: { Accept: 'application/json' } });
      setState(res.ok ? 'unsubscribed' : res.status === 403 ? 'invalid' : 'unavailable');
    } catch {
      setState('unavailable');
    }
  };

  return (
    <div className="flex flex-col gap-4 rounded-18 border border-hairline bg-white p-fluid-18-26">
      <h1 className="text-fluid-19-23 font-bold tracking-title">{t('title')}</h1>
      {state === 'loading' ? <p className="text-14 text-muted">{t('checking')}</p> : null}
      {state === 'subscribed' || state === 'sending' ? (
        <>
          <p className="text-14 leading-1.6 text-muted">{t('confirm', { email })}</p>
          <button type="button" onClick={unsubscribe} aria-disabled={state === 'sending'} className={buttonClass('cta', 'lg', 'self-start')}>
            {t('cta')}
          </button>
        </>
      ) : null}
      {state === 'unsubscribed' ? (
        <p role="status" className="text-14 leading-1.6 font-semibold text-green-deep">
          {t('done')}
        </p>
      ) : null}
      {state === 'invalid' || state === 'unavailable' ? (
        <>
          <p role="alert" className="text-14 leading-1.6 font-semibold text-red">
            {state === 'invalid' ? t('invalid') : t('unavailable')}
          </p>
          <a href={whatsappUrl(settings.contact.whatsapp)} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineDark', 'md', 'self-start')}>
            {t('contact')}
          </a>
        </>
      ) : null}
    </div>
  );
}
