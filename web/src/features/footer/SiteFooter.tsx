import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { displayPhone, teamPath, telUrl } from '@/lib/links';

const LINKS = [
  ['/#packages', 'packages'],
  ['/#departures', 'departures'],
  ['/#visa', 'sheet.visa'],
  ['/#about', 'about'],
  [teamPath, 'sheet.team'],
  ['/#blog', 'news'],
  ['/#faq', 'sheet.faq'],
  ['/#contact', 'contact'],
] as const;

/** Logo, section links, contact and licence (README §16) on the prototype's dark footer. */
export async function SiteFooter({ locale, settings, emptySections = [] }: { locale: AppLocale; settings: SiteViews['settings']; emptySections?: string[] }) {
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  // A year is an identifier: localized digits, never thousands grouping.
  const year = f.digits(String(new Date().getFullYear()));

  return (
    <footer className="bg-navy-abyss px-5 py-6 text-center text-13 text-white opacity-85">
      <Image src="/brand/logo-wordmark-light.png" alt={settings.brand} width={852} height={378} className="mx-auto mb-2.5 block h-logo-footer w-auto" />
      <nav aria-label={t('footer.explore')} className="mb-3 flex flex-wrap justify-center gap-x-4 gap-y-1.5">
        {LINKS.filter(([href]) => !emptySections.some((id) => href === `/#${id}`)).map(([href, key]) => (
          <Link key={href} href={href} className="text-white hover:text-orange-light">
            {t(`nav.${key}`)}
          </Link>
        ))}
      </nav>
      <p className="mb-1.5 flex flex-wrap justify-center gap-x-3 gap-y-1">
        <a href={telUrl(settings.contact.phone)} className="text-white hover:text-orange-light">
          {f.digits(displayPhone(settings.contact.phone))}
        </a>
        <span aria-hidden>·</span>
        <a href={`mailto:${settings.contact.email}`} className="text-white hover:text-orange-light">
          {settings.contact.email}
        </a>
      </p>
      {settings.contact.notificationsWhatsapp ? (
        <p className="mb-1.5">
          {t('contact.notifications')} <span className="font-display">{f.digits(displayPhone(settings.contact.notificationsWhatsapp))}</span>
        </p>
      ) : null}
      <p className="mb-1.5">{settings.address}</p>
      <p className="mb-2">{t('footer.licence', { licence: f.digits(settings.civilAviationNo) })}</p>
      <p>{t('footer.copyright', { year })}</p>
    </footer>
  );
}
