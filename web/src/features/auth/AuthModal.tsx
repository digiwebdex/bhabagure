'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Modal, ModalClose } from '@/components/ui/Modal';
import type { SessionCustomer } from '@/state/customer-session';
import { useSiteUi } from '@/state/site-ui';

import { CodeSignIn } from './CodeSignIn';

/** Sign in by one-time code. On success the header pill becomes the customer's initials, linking to the portal. */
export function AuthModal() {
  const t = useTranslations('auth');
  const tc = useTranslations('common');
  const open = useSiteUi((state) => state.authOpen);
  const close = useSiteUi((state) => state.closeAuth);
  const { settings } = useSiteContent();
  const [signedIn, setSignedIn] = useState<SessionCustomer | null>(null);

  const onClose = () => {
    close();
    setSignedIn(null);
  };

  const portalUrl = process.env.NEXT_PUBLIC_PORTAL_URL ?? '/';

  return (
    <Modal open={open} onClose={onClose} labelledBy="auth-title" size="sm" layer="auth">
      <div className="overflow-y-auto">
        <div className="flex items-start justify-between gap-3.5 px-fluid-18-26 pt-5">
          <div className="flex min-w-0 flex-col gap-1">
            <h2 id="auth-title" className="text-fluid-19-23 font-bold tracking-title">
              {t('signInTitle')}
            </h2>
            <span className="text-13.5 leading-1.55 text-muted">{t('signInSubtitle')}</span>
          </div>
          <ModalClose onClick={onClose} label={tc('close')} size="sm" />
        </div>

        <div className="px-fluid-18-26 pt-4 pb-5.5">
          {signedIn ? (
            <div className="flex flex-col gap-3.5">
              <div role="status" className="flex flex-col gap-1.25 rounded-14 border border-green-line bg-green-tint p-4 text-green-deep">
                <strong className="text-15 font-bold">{t('signedInTitle', { name: signedIn.name })}</strong>
                <span className="text-13.5 leading-1.6">{t('signedInNote')}</span>
              </div>
              <a href={portalUrl} className={buttonClass('cta', 'block')}>
                {t('goPortal')}
              </a>
            </div>
          ) : open ? (
            <CodeSignIn onSignedIn={setSignedIn} whatsapp={settings.contact.whatsapp} />
          ) : null}
        </div>
      </div>
    </Modal>
  );
}
