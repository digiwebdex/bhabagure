import { getTranslations } from 'next-intl/server';

import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { tourPagePath } from '@/lib/links';

import { Countdown } from './Countdown';

/**
 * "Upcoming group tours" on the home page (docs/tour-landing-pages.md): a card for each landing page staff promote whose
 * tour has a departure to come, linking to the page. A tour with no date to come drops off by itself; its page stays.
 * One soft pulse on the badge's dot, still for visitors who ask for less motion.
 */
export async function UpcomingToursSection({ locale, pages }: { locale: AppLocale; pages: SiteViews['tourPages'] }) {
  const shown = pages
    .filter((page) => page.promote && page.countdown !== null)
    .sort((a, b) => a.order - b.order || a.countdown!.departsOn.localeCompare(b.countdown!.departsOn));
  if (shown.length === 0) return null;
  const t = await getTranslations({ locale, namespace: 'tourPage' });
  const tb = await getTranslations({ locale, namespace: 'booking' });
  const f = formattersFor(locale);

  return (
    <section id="upcoming-tours" className="mx-auto w-full max-w-site px-section-x pt-fluid-28-44" data-testid="upcoming-tours">
      <div className="mb-4 flex flex-col gap-1">
        <span className="font-display text-label text-orange-deep uppercase">{t('promoEyebrow')}</span>
        <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('promoHeading')}</h2>
      </div>
      <ul className="scrollbar-none -mx-section-x m-0 flex list-none snap-x snap-mandatory gap-4 overflow-x-auto px-section-x pb-2 md:mx-0 md:grid md:grid-cols-2 md:overflow-visible md:px-0 lg:grid-cols-3">
        {shown.map((page) => {
          const d = page.countdown!;
          return (
            <li key={page.slug} className="w-[82%] shrink-0 snap-start md:w-auto">
              <Link
                href={tourPagePath(page.slug)}
                className="group flex h-full flex-col overflow-hidden rounded-20 bg-white text-ink shadow-card transition hover:-translate-y-0.5 hover:text-ink hover:shadow-card-hover"
                data-testid="upcoming-tour-card"
              >
                <span className="relative block aspect-16/10 overflow-hidden bg-ink-deep">
                  {page.hero ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={page.hero.image.url} srcSet={page.hero.srcSet ?? undefined} sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 82vw" alt="" loading="lazy" className="size-full object-cover transition duration-500 group-hover:scale-103" />
                  ) : null}
                  <span className="absolute top-3 left-3 flex items-center gap-1.5 rounded-pill bg-white/95 px-3 py-1 text-12 font-bold text-orange-deep shadow-card-soft">
                    <span aria-hidden className="relative flex size-2">
                      <span className="absolute inline-flex size-full rounded-full bg-orange opacity-60 motion-safe:animate-ping motion-safe:[animation-iteration-count:3]" />
                      <span className="relative inline-flex size-2 rounded-full bg-orange-deep" />
                    </span>
                    {t('bookingOpen')}
                  </span>
                </span>
                <span className="flex flex-1 flex-col gap-1.5 p-4.5">
                  <span className="text-17 leading-1.3 font-bold">{page.title}</span>
                  <span className="text-14 text-muted">{f.dateRange(d.departsOn, d.returnsOn)}</span>
                  <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-13">
                    <span className="text-orange-deep">
                      <Countdown departsOn={d.departsOn} compact />
                    </span>
                    <span className="text-muted">{d.seatsLeft === 0 ? tb('soldOut') : tb('seatsLeft', { seats: d.seatsLeft, seatsText: f.number(d.seatsLeft) })}</span>
                  </span>
                  <span className="mt-auto flex items-end justify-between gap-3 pt-2">
                    <span className="font-display text-20 font-extrabold text-orange-deep">{tb('departurePrice', { price: f.bdt(d.price) })}</span>
                    <span className="text-13 font-semibold text-blue">{t('fullPlan')} →</span>
                  </span>
                </span>
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
