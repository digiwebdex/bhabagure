import 'server-only';

import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { packageLabels } from '@/features/packages/package-labels';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { formattersFor } from '@/lib/formatters';
import { localizedPath, packagePath } from '@/lib/links';
import { basePrice } from '@/lib/package-price';

/** The website's cards price for two travellers until the visitor changes the count (state/trip-search.ts). */
const CARD_PAX = 2;

/**
 * "Book another trip" under the customer's bookings (client, 2026-10-03): every package published on the website, in
 * its order and at the price its card shows. A card opens the package on the website; Book opens it with the booking
 * form already open (`?book=1`, PackageDetailActions), so a booking from here is the same as any other.
 */
export async function MorePackages({ locale }: { locale: AppLocale }) {
  const { packages, pricing } = await getSiteViews(locale);
  if (packages.length === 0) return null;
  const t = await getTranslations({ locale, namespace: 'portal.trips.more' });
  const tp = await getTranslations({ locale, namespace: 'packages' });
  const f = formattersFor(locale);
  const site = siteUrl();

  return (
    <section aria-labelledby="more-packages" className="flex flex-col gap-3.5 pt-2" data-testid="more-packages">
      <div className="flex flex-wrap items-end justify-between gap-2.5">
        <div className="flex flex-col gap-1">
          <h2 id="more-packages" className="m-0 text-19 font-semibold">
            {t('title')}
          </h2>
          <p className="m-0 text-13.5 text-app-muted">{t('subtitle')}</p>
        </div>
        <a href={`${site}${localizedPath(locale, '/')}#packages`} className="text-13.5 font-semibold whitespace-nowrap text-blue hover:text-orange">
          {t('allPackages')} →
        </a>
      </div>
      <div className="grid-auto-fill-260 grid gap-3.5">
        {packages.map((pkg) => {
          const page = `${site}${localizedPath(locale, packagePath(pkg.slug))}`;
          const cover = pkg.images[0];
          const labels = packageLabels(pkg, tp, f);
          return (
            <article
              key={pkg.slug}
              className="group relative flex flex-col overflow-hidden rounded-18 border border-portal-line bg-app-surface transition duration-200 ease-lift hover:-translate-y-0.5 hover:border-blue motion-reduce:transition-none motion-reduce:hover:translate-y-0"
            >
              <div className="relative h-36 w-full bg-image-placeholder">
                {cover ? <Image src={cover.url} alt={cover.alt} fill sizes="(min-width: 900px) 300px, (min-width: 600px) 50vw, 100vw" className="object-cover" /> : null}
                <span className="pointer-events-none absolute top-2.5 left-2.5 rounded-pill bg-orange-deep px-2.5 py-1 font-display text-label text-white uppercase">
                  {pkg.destinationLabel}
                </span>
              </div>
              <div className="flex flex-1 flex-col gap-2 p-4">
                <h3 className="m-0 text-16 leading-1.3 font-semibold">
                  {/* Stretched over the card: the whole card opens the package's page on the website. */}
                  <a href={page} className="text-app-text after:absolute after:inset-0 hover:text-app-text focus-visible:outline-none">
                    {pkg.title}
                  </a>
                </h3>
                <span className="text-13 text-app-muted">{labels.duration} · {labels.groupSize}</span>
                <div className="mt-auto flex items-end justify-between gap-2.5 border-t border-portal-line pt-2.5">
                  <span className="flex flex-col">
                    <span className="text-11 text-app-muted">{t('from')}</span>
                    <span className="font-display text-17 font-extrabold text-orange-deep">{f.bdt(basePrice(pkg, CARD_PAX, pricing.slabs))}</span>
                    <span className="text-11 text-app-muted">{t('perPerson')}</span>
                  </span>
                  <a
                    href={`${page}?book=1`}
                    aria-label={t('bookLabel', { title: pkg.title })}
                    className="relative z-10 rounded-pill bg-blue px-4.5 py-2.25 text-13.5 font-semibold whitespace-nowrap text-white hover:bg-orange hover:text-white"
                  >
                    {t('book')}
                  </a>
                </div>
              </div>
            </article>
          );
        })}
      </div>
    </section>
  );
}
