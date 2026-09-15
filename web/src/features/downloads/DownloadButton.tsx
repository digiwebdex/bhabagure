'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { downloadPortalFile, type DownloadResult } from '@/lib/customer-api';
import { useSiteUi } from '@/state/site-ui';

/**
 * A brochure or visa-requirements PDF (docs/phase-8-visa-quotes-pricing-downloads.md §4.E). Signed-in customers get the
 * file straight away; anyone else signs in with a phone code first and the download then starts by itself.
 */
export function DownloadButton({ path, filename, label, className, testId }: { path: string; filename: string; label: string; className: string; testId?: string }) {
  const t = useTranslations('download');
  const locale = useLocale();
  const openAuth = useSiteUi((state) => state.openAuth);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<Exclude<DownloadResult, 'ok' | 'signed_out'> | null>(null);

  const start = async () => {
    setBusy(true);
    setProblem(null);
    const result = await downloadPortalFile(path, filename, locale);
    setBusy(false);
    if (result === 'signed_out') openAuth({ path, filename });
    else if (result !== 'ok') setProblem(result);
  };

  return (
    <span className="inline-flex flex-col gap-1">
      <button type="button" onClick={start} disabled={busy} aria-busy={busy} className={className} data-testid={testId}>
        <DownloadIcon />
        {busy ? t('working') : label}
      </button>
      {problem ? (
        <span role="alert" className="max-w-72 text-12.5 leading-1.5 text-red">
          {problem === 'rate_limited' ? t('tooMany') : t('failed')}
        </span>
      ) : null}
    </span>
  );
}

export function DownloadIcon() {
  return (
    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 3v12m0 0-5-5m5 5 5-5M5 21h14" />
    </svg>
  );
}
