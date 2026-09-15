'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Modal, ModalClose } from '@/components/ui/Modal';
import { DownloadIcon } from '@/features/downloads/DownloadButton';
import { downloadPortalFile, type DownloadResult } from '@/lib/customer-api';
import type { SessionCustomer } from '@/state/customer-session';
import { useSiteUi } from '@/state/site-ui';

import { CodeSignIn } from './CodeSignIn';

/**
 * Sign in by one-time code. On success the header pill becomes the customer's initials, linking to the portal. Opened
 * by a brochure or visa download (Phase 8 §4.E), it says so, and starts that download once the visitor is signed in.
 */
export function AuthModal() {
  const t = useTranslations('auth');
  const td = useTranslations('download');
  const tc = useTranslations('common');
  const locale = useLocale();
  const open = useSiteUi((state) => state.authOpen);
  const close = useSiteUi((state) => state.closeAuth);
  const pending = useSiteUi((state) => state.pendingDownload);
  const { settings } = useSiteContent();
  const [signedIn, setSignedIn] = useState<SessionCustomer | null>(null);
  const [download, setDownload] = useState<DownloadResult | 'working' | null>(null);

  const onClose = () => {
    close();
    setSignedIn(null);
    setDownload(null);
  };

  const startDownload = async () => {
    if (!pending) return;
    setDownload('working');
    setDownload(await downloadPortalFile(pending.path, pending.filename, locale));
  };

  const onSignedIn = (customer: SessionCustomer) => {
    setSignedIn(customer);
    void startDownload();
  };

  const portalUrl = process.env.NEXT_PUBLIC_PORTAL_URL ?? '/';

  return (
    <Modal open={open} onClose={onClose} labelledBy="auth-title" size="sm" layer="auth">
      <div className="overflow-y-auto">
        <div className="flex items-start justify-between gap-3.5 px-fluid-18-26 pt-5">
          <div className="flex min-w-0 flex-col gap-1">
            <h2 id="auth-title" className="text-fluid-19-23 font-bold tracking-title">
              {pending ? td('signInTitle') : t('signInTitle')}
            </h2>
            <span className="text-13.5 leading-1.55 text-muted">{pending ? td('signInSubtitle') : t('signInSubtitle')}</span>
          </div>
          <ModalClose onClick={onClose} label={tc('close')} size="sm" />
        </div>

        <div className="px-fluid-18-26 pt-4 pb-5.5">
          {signedIn ? (
            <div className="flex flex-col gap-3.5">
              <div role="status" className="flex flex-col gap-1.25 rounded-14 border border-green-line bg-green-tint p-4 text-green-deep">
                <strong className="text-15 font-bold">{t('signedInTitle', { name: signedIn.name })}</strong>
                <span className="text-13.5 leading-1.6">
                  {!pending ? t('signedInNote') : download === 'rate_limited' ? td('tooMany') : download === 'ok' || download === 'working' ? td('started') : td('failed')}
                </span>
              </div>
              {pending ? (
                <button type="button" onClick={startDownload} disabled={download === 'working'} className={buttonClass('outlineInk', 'block')}>
                  <DownloadIcon />
                  {download === 'working' ? td('working') : td('again')}
                </button>
              ) : null}
              <a href={portalUrl} className={buttonClass('cta', 'block')}>
                {t('goPortal')}
              </a>
            </div>
          ) : open ? (
            <CodeSignIn onSignedIn={onSignedIn} whatsapp={settings.contact.whatsapp} />
          ) : null}
        </div>
      </div>
    </Modal>
  );
}
