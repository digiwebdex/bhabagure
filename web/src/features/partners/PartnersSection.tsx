import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';

/**
 * The airlines the agency books (docs/partners-and-payments.md): a quiet band of logos closing the page, above the
 * footer's payment line. Renders nothing until Admin → Airline partners has a published one.
 */
export async function PartnersSection({ locale, partners }: { locale: AppLocale; partners: SiteViews['partners'] }) {
  if (partners.length === 0) return null;
  const t = await getTranslations({ locale });

  return (
    <section id="partners" className="border-t border-hairline-soft bg-paper-soft">
      <div className="mx-auto flex max-w-site flex-col items-center gap-5 px-section-x py-10">
        <h2 data-reveal className="font-display text-13 font-bold tracking-eyebrow text-muted-label uppercase">
          {t('sections.partners.heading')}
        </h2>
        <ul className="m-0 flex list-none flex-wrap items-center justify-center gap-3 p-0" data-testid="airline-partners">
          {partners.map((partner) => {
            const logo = (
              <Image
                src={partner.logo.url}
                alt={partner.name}
                width={220}
                height={80}
                sizes="150px"
                className="h-9 w-auto max-w-32 object-contain opacity-85 transition duration-300 ease-lift group-hover:opacity-100"
              />
            );
            return (
              <li key={partner.name} data-reveal>
                {partner.websiteUrl ? (
                  <a
                    href={partner.websiteUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="group flex h-16 w-36 items-center justify-center rounded-14 border border-hairline bg-white px-3 transition duration-300 ease-lift hover:-translate-y-1 hover:border-hairline-hover hover:shadow-card-soft"
                  >
                    {logo}
                  </a>
                ) : (
                  <span className="group flex h-16 w-36 items-center justify-center rounded-14 border border-hairline bg-white px-3">{logo}</span>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
