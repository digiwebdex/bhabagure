'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState, useSyncExternalStore } from 'react';

const noSubscription = () => () => {};

/**
 * Opens a staff preview link (docs/tour-landing-pages.md §6): its token is in the #hash, which no server ever receives.
 * It is posted to /api/tour-preview (kept there in an HttpOnly cookie), taken out of the address, and the page reloads
 * as the preview. With no token here the server's answer stands: the preview, or "expired or invalid".
 */
export function PreviewStart({ showInvalid }: { showInvalid: boolean }) {
  const t = useTranslations('tourPage');
  const [failed, setFailed] = useState(false);
  // A link with a token is being opened: no "expired" while it is checked (false on the server, so no mismatch).
  const opening = useSyncExternalStore(noSubscription, () => window.location.hash.length > 1, () => false);

  useEffect(() => {
    const token = window.location.hash.slice(1);
    if (!token) return;
    window.history.replaceState(window.history.state, '', window.location.pathname);
    void fetch('/api/tour-preview', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token }), cache: 'no-store' })
      .then((res) => (res.ok ? window.location.reload() : setFailed(true)))
      .catch(() => setFailed(true));
  }, []);

  if (!failed && (opening || !showInvalid)) return null;
  return (
    <div className="mx-auto flex max-w-xl flex-col gap-2 px-section-x py-section-y text-center" data-testid="tour-preview-invalid">
      <h1 className="text-fluid-20-26 font-bold">{t('previewInvalidHeading')}</h1>
      <p className="text-15 text-muted">{t('previewInvalidText')}</p>
    </div>
  );
}
