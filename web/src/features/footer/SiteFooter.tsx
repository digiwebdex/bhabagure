import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { FacebookGlyph, InstagramGlyph, LinkedInGlyph, TikTokGlyph, YouTubeGlyph } from '@/components/brand/SocialGlyphs';
import { WhatsAppGlyph } from '@/components/brand/WhatsAppGlyph';
import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { displayPhone, teamPath, telUrl, whatsappUrl } from '@/lib/links';

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
  // The company's social links (client, 2026-10-02), from Site settings → Contact, and WhatsApp from the main number;
  // a link left empty shows no icon.
  const contact = settings.contact;
  const social = [
    { name: 'Facebook', href: contact.facebook, Glyph: FacebookGlyph },
    { name: 'Instagram', href: contact.instagram, Glyph: InstagramGlyph },
    { name: 'TikTok', href: contact.tiktok, Glyph: TikTokGlyph },
    { name: 'LinkedIn', href: contact.linkedin, Glyph: LinkedInGlyph },
    { name: 'YouTube', href: contact.youtube, Glyph: YouTubeGlyph },
    { name: 'WhatsApp', href: contact.whatsapp ? whatsappUrl(contact.whatsapp) : null, Glyph: WhatsAppGlyph },
  ].filter((item): item is typeof item & { href: string } => typeof item.href === 'string' && item.href.trim() !== '');

  return (
    <footer className="bg-navy-abyss px-5 py-6 text-center text-13 text-white opacity-85">
      <Image src="/brand/logo-wordmark-light.png" alt={settings.brand} width={852} height={378} className="mx-auto mb-2.5 block h-logo-footer w-auto" />
      {social.length > 0 ? (
        <ul aria-label={t('footer.social')} className="mx-auto mb-3.5 flex list-none flex-wrap justify-center gap-2.5 p-0" data-testid="footer-social">
          {social.map(({ name, href, Glyph }) => (
            <li key={name}>
              <a
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={t('footer.socialLink', { name })}
                title={name}
                className="flex size-10 items-center justify-center rounded-full border border-white/20 bg-white/8 text-white transition duration-200 ease-lift hover:-translate-y-0.5 hover:border-orange hover:bg-orange hover:text-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
              >
                <Glyph className="size-4.5" />
              </a>
            </li>
          ))}
        </ul>
      ) : null}
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

      {/* Every method the gateway takes, on one line, from SSLCommerz's own strip so it stays right as they add banks.
          Wider than a phone: it scrolls sideways rather than shrinking the marks past reading. */}
      <div className="mx-auto mb-3 max-w-6xl overflow-x-auto px-1 pb-1">
        <Image
          src="/payments/pay-with-sslcommerz.png"
          alt={t('footer.payWith')}
          width={9561}
          height={314}
          sizes="(min-width: 1200px) 1150px, 300vw"
          className="mx-auto h-9 w-auto max-w-none rounded-6 bg-white px-2 py-1"
        />
      </div>

      <p>{t('footer.copyright', { year })}</p>
    </footer>
  );
}
