'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useRef } from 'react';

import { WhatsAppGlyph } from '@/components/brand/WhatsAppGlyph';
import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { whatsappUrl } from '@/lib/links';

/** Bottom-right: back-to-top (after 500px of scrolling) above the floating WhatsApp button. */
export function FloatingActions() {
  const t = useTranslations('floating');
  const { settings } = useSiteContent();
  const toTop = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const update = () => {
      const visible = window.scrollY > 500;
      const el = toTop.current;
      if (!el) return;
      el.style.opacity = visible ? '1' : '0';
      el.style.pointerEvents = visible ? 'auto' : 'none';
      el.tabIndex = visible ? 0 : -1;
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
    return () => window.removeEventListener('scroll', update);
  }, []);

  return (
    <div className="fixed right-fluid-10-18 bottom-fluid-12-18 z-55 flex flex-col items-center gap-fluid-9-12">
      <button
        ref={toTop}
        type="button"
        tabIndex={-1}
        title={t('backToTop')}
        aria-label={t('backToTop')}
        onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
        className="pointer-events-none flex size-fab-sm cursor-pointer items-center justify-center rounded-full border border-hairline bg-white text-20 text-blue opacity-0 shadow-raised transition-[opacity,transform] duration-250 hover:-translate-y-0.5"
      >
        ↑
      </button>
      <a
        href={whatsappUrl(settings.contact.whatsapp)}
        target="_blank"
        rel="noopener noreferrer"
        title={t('whatsapp')}
        aria-label={t('whatsapp')}
        className="flex size-fab animate-float items-center justify-center rounded-full bg-whatsapp text-white shadow-fab-whatsapp transition-transform duration-200 hover:scale-106 hover:text-white"
      >
        <WhatsAppGlyph className="size-glyph-fab" />
      </a>
    </div>
  );
}
