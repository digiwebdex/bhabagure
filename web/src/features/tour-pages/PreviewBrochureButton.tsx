'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { DownloadIcon } from '@/features/downloads/DownloadButton';

type Problem = 'expired' | 'rate_limited' | 'render_failed';

/**
 * On the staff preview only: the draft printed as its brochure, as customers will get it once the page is published
 * and the brochure is switched on (docs/tour-landing-pages.md §7). Made fresh each time, so it takes a little while.
 */
export function PreviewBrochureButton({ className }: { className: string }) {
  const t = useTranslations('tourPage');
  const locale = useLocale();
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<Problem | null>(null);

  const start = async () => {
    setBusy(true);
    setProblem(null);
    try {
      const res = await fetch(`/api/tour-preview/brochure?locale=${locale}`, { cache: 'no-store' });
      if (!res.ok) {
        const body = (await res.json().catch(() => null)) as { code?: Problem } | null;
        setProblem(body?.code ?? 'render_failed');
        return;
      }
      const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'bhabaghure-preview.pdf';
      const url = URL.createObjectURL(await res.blob());
      const link = Object.assign(document.createElement('a'), { href: url, download: name });
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
    } catch {
      setProblem('render_failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex flex-col gap-1">
      <button type="button" onClick={start} disabled={busy} aria-busy={busy} className={className} data-testid="tour-preview-brochure">
        <DownloadIcon />
        {busy ? t('previewBrochureWorking') : t('previewBrochure')}
      </button>
      {problem ? (
        <span role="alert" className="max-w-80 text-12.5 leading-1.5 text-red">
          {problem === 'expired' ? t('previewBrochureExpired') : problem === 'rate_limited' ? t('previewBrochureTooMany') : t('previewBrochureFailed')}
        </span>
      ) : null}
    </span>
  );
}
