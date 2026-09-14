'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useCallback, useEffect, useRef } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { Modal, ModalClose } from '@/components/ui/Modal';
import type { AppLocale } from '@/i18n/routing';
import { localizedPath, packagePath, packageSlugFromPathname } from '@/lib/links';
import { useSiteUi } from '@/state/site-ui';
import { useTripSearch } from '@/state/trip-search';

import { PackageDetailActions, PackageDetailBody, PackageDetailHeading } from './PackageDetail';

/**
 * The package detail modal and its URL. Opening from a card pushes /packages/<slug>, so the link
 * is shareable and the back button closes the modal; a direct visit to that URL renders the full
 * package page instead (app/[locale]/site/packages/[slug]).
 */
export function PackageModalHost({ homePath }: { homePath: string }) {
  const locale = useLocale() as AppLocale;
  const t = useTranslations('common');
  const { packages } = useSiteContent();
  const slug = useSiteUi((state) => state.packageSlug);
  const openedBy = useSiteUi((state) => state.packageOpenedBy);
  const openPackage = useSiteUi((state) => state.openPackage);
  const closePackage = useSiteUi((state) => state.closePackage);
  const pushed = useRef(false);

  const pkg = slug ? packages.find((p) => p.slug === slug) ?? null : null;

  // Click → push a history entry for the package URL.
  useEffect(() => {
    if (slug && openedBy === 'click' && !pushed.current) {
      window.history.pushState(null, '', localizedPath(locale, packagePath(slug)));
      pushed.current = true;
    }
  }, [slug, openedBy, locale]);

  // Back / forward.
  useEffect(() => {
    const onPopState = () => {
      const fromUrl = packageSlugFromPathname(window.location.pathname);
      pushed.current = false;
      if (fromUrl && packages.some((p) => p.slug === fromUrl)) {
        openPackage(fromUrl, useTripSearch.getState().pax, 'history');
      } else {
        closePackage();
      }
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [packages, openPackage, closePackage]);

  const close = useCallback(() => {
    if (pushed.current) {
      window.history.back(); // popstate closes the modal
      return;
    }
    if (packageSlugFromPathname(window.location.pathname)) {
      window.history.replaceState(null, '', homePath);
    }
    closePackage();
  }, [closePackage, homePath]);

  return (
    <Modal open={pkg !== null} onClose={close} labelledBy="package-detail-title" size="md" layer="detail">
      {pkg ? (
        <>
          <div className="flex shrink-0 items-start justify-between gap-3.5 border-b border-hairline px-fluid-18-28 py-5">
            <PackageDetailHeading pkg={pkg} id="package-detail-title" />
            <ModalClose onClick={close} label={t('close')} />
          </div>
          <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-fluid-18-28 pt-detail-body-top pb-fluid-22-28">
            <PackageDetailBody pkg={pkg} />
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-hairline bg-paper-soft px-fluid-18-28 py-4">
            <PackageDetailActions pkg={pkg} onBeforeBook={close} />
          </div>
        </>
      ) : null}
    </Modal>
  );
}
