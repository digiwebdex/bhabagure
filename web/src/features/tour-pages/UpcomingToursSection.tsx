import { getTranslations } from 'next-intl/server';

import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { PackageDepartureView, SiteViews, TourPageView } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { tourPagePath } from '@/lib/links';

import { Countdown } from './Countdown';

type Formatters = ReturnType<typeof formattersFor>;
type Translate = Awaited<ReturnType<typeof getTranslations<'tourPage'>>>;

/**
 * "Upcoming group tours" on the home page, after the offer banners (docs/tour-landing-pages.md §9): the promoted tour
 * that leaves soonest as a large photo banner — "Booking open", the time left, its dates and how full it is — and the
 * other promoted tours as smaller cards beneath, in staff's order. Each links to its landing page. A tour with no date
 * to come drops off by itself; its page stays. The badge's ring widens and fades softly, and is still for visitors
 * who ask for less motion.
 */
export async function UpcomingToursSection({ locale, pages }: { locale: AppLocale; pages: SiteViews['tourPages'] }) {
  const shown = pages
    .filter((page) => page.promote && page.countdown !== null)
    .sort((a, b) => a.order - b.order || a.countdown!.departsOn.localeCompare(b.countdown!.departsOn));
  if (shown.length === 0) return null;
  // The soonest with seats; the soonest of all when every one is full.
  const bySoonest = [...shown].sort((a, b) => a.countdown!.departsOn.localeCompare(b.countdown!.departsOn));
  const lead = bySoonest.find((page) => page.countdown!.seatsLeft > 0) ?? bySoonest[0];
  const rest = shown.filter((page) => page !== lead);
  const t = await getTranslations({ locale, namespace: 'tourPage' });
  const tb = await getTranslations({ locale, namespace: 'booking' });
  const f = formattersFor(locale);

  return (
    <section id="upcoming-tours" className="mx-auto w-full max-w-site px-section-x pt-fluid-28-44" data-testid="upcoming-tours">
      <div className="mb-4 flex flex-col gap-1">
        <span className="font-display text-label text-orange-deep uppercase">{t('promoEyebrow')}</span>
        <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('promoHeading')}</h2>
      </div>

      <Banner page={lead} t={t} tb={tb} f={f} />

      {rest.length > 0 ? (
        <>
          <h3 className="mt-6 mb-3 text-17 font-bold">{t('moreTours')}</h3>
          <ul className="scrollbar-none -mx-section-x m-0 flex list-none snap-x snap-mandatory gap-4 overflow-x-auto px-section-x pb-2 md:mx-0 md:grid md:grid-cols-2 md:overflow-visible md:px-0 lg:grid-cols-3">
            {rest.map((page) => (
              <li key={page.slug} className="w-[82%] shrink-0 snap-start md:w-auto">
                <Card page={page} t={t} tb={tb} f={f} />
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </section>
  );
}

function Banner({ page, t, tb, f }: { page: TourPageView; t: Translate; tb: Translate; f: Formatters }) {
  const d = page.countdown!;
  const { pkg } = page;
  const duration =
    pkg.durationNights !== null
      ? t('durationNights', { days: f.number(pkg.durationDays), nights: f.number(pkg.durationNights) })
      : t('durationDays', { days: f.number(pkg.durationDays) });

  return (
    <Link
      href={tourPagePath(page.slug)}
      className="group relative isolate flex min-h-[36rem] items-end overflow-hidden rounded-22 bg-ink-deep text-white shadow-card transition hover:text-white hover:shadow-card-hover sm:min-h-[30rem] md:min-h-[27rem] md:items-center"
      data-testid="upcoming-tour-banner"
    >
      {page.hero ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={page.hero.image.url}
          srcSet={page.hero.srcSet ?? undefined}
          sizes="(min-width: 1280px) 1200px, 100vw"
          alt=""
          loading="lazy"
          className="absolute inset-0 -z-10 size-full object-cover transition duration-700 ease-lift group-hover:scale-103"
        />
      ) : null}
      {/* Dark under the words: from the foot on a phone, from the left on a wider screen. */}
      <span aria-hidden className="absolute inset-0 -z-10 bg-linear-to-t from-black/90 via-black/55 to-black/5 md:bg-linear-to-r md:from-black/85 md:via-black/55 md:to-black/0" />

      <div className="flex w-full max-w-2xl flex-col gap-3.5 p-fluid-20-32">
        <span className="flex flex-wrap items-center gap-2">
          <span className="flex items-center gap-2 rounded-pill bg-white px-3.5 py-1.5 text-13 font-bold text-orange-deep motion-safe:animate-soft-pulse" data-testid="booking-open">
            <span aria-hidden className="size-2 rounded-full bg-orange" />
            {t('bookingOpen')}
          </span>
          {d.isGuaranteed ? (
            <span className="rounded-pill bg-green/90 px-3 py-1.5 text-12 font-bold text-white">{t('guaranteed')}</span>
          ) : null}
        </span>
        <span className="font-display text-label text-orange-light uppercase">
          {pkg.destinationLabel} · {duration}
        </span>
        <span className="font-display text-fluid-30-42 leading-1.15 font-extrabold tracking-title text-balance">{page.title}</span>
        <span className="text-16 font-semibold text-white/90">{f.dateRange(d.departsOn, d.returnsOn)}</span>
        <div className="flex flex-col gap-1.5">
          <span className="text-12 font-semibold text-white/75 uppercase">{t('departsIn')}</span>
          <Countdown departsOn={d.departsOn} tone="dark" />
        </div>
        <SeatBar departure={d} t={t} tb={tb} f={f} tone="dark" />
        <span className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <span className="font-display text-fluid-20-26 font-extrabold text-orange-light">{tb('departurePrice', { price: f.bdt(d.price) })}</span>
          <span className="rounded-pill bg-orange-deep px-5 py-2.5 text-15 font-bold text-white transition group-hover:bg-orange">{t('fullPlan')} →</span>
        </span>
      </div>
    </Link>
  );
}

function Card({ page, t, tb, f }: { page: TourPageView; t: Translate; tb: Translate; f: Formatters }) {
  const d = page.countdown!;
  return (
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
          <span aria-hidden className="size-2 rounded-full bg-orange" />
          {t('bookingOpen')}
        </span>
      </span>
      <span className="flex flex-1 flex-col gap-1.5 p-4.5">
        <span className="text-17 leading-1.3 font-bold">{page.title}</span>
        <span className="text-14 text-muted">{f.dateRange(d.departsOn, d.returnsOn)}</span>
        <span className="text-13 text-orange-deep">
          <Countdown departsOn={d.departsOn} compact />
        </span>
        <SeatBar departure={d} t={t} tb={tb} f={f} tone="light" />
        <span className="mt-auto flex items-end justify-between gap-3 pt-2">
          <span className="font-display text-20 font-extrabold text-orange-deep">{tb('departurePrice', { price: f.bdt(d.price) })}</span>
          <span className="text-13 font-semibold text-blue">{t('fullPlan')} →</span>
        </span>
      </span>
    </Link>
  );
}

/** How full the date is: seats booked of the total, as a bar, with the seats left beside it. */
function SeatBar({ departure: d, t, tb, f, tone }: { departure: PackageDepartureView; t: Translate; tb: Translate; f: Formatters; tone: 'light' | 'dark' }) {
  const left = d.seatsLeft === 0 ? tb('soldOut') : tb('seatsLeft', { seats: d.seatsLeft, seatsText: f.number(d.seatsLeft) });
  if (d.seatsTotal <= 0) return <span className={`text-13 ${tone === 'dark' ? 'text-white/85' : 'text-muted'}`}>{left}</span>;
  const booked = Math.max(0, d.seatsTotal - d.seatsLeft);
  const percent = Math.min(100, Math.round((booked / d.seatsTotal) * 100));

  return (
    <span className="flex flex-col gap-1.5" data-testid="seat-bar">
      <span className={`flex flex-wrap items-baseline justify-between gap-x-3 text-13 ${tone === 'dark' ? 'text-white/85' : 'text-muted'}`}>
        <span>{t('seatsFilled', { bookedText: f.number(booked), totalText: f.number(d.seatsTotal) })}</span>
        <span className={`font-semibold ${tone === 'dark' ? 'text-orange-light' : 'text-orange-deep'}`}>{left}</span>
      </span>
      <span
        role="meter"
        aria-valuemin={0}
        aria-valuemax={d.seatsTotal}
        aria-valuenow={booked}
        aria-label={t('seatsFilled', { bookedText: f.number(booked), totalText: f.number(d.seatsTotal) })}
        className={`block h-2 overflow-hidden rounded-pill ${tone === 'dark' ? 'bg-white/20' : 'bg-paper-alt'}`}
      >
        <span className="block h-full rounded-pill bg-linear-to-r from-orange to-orange-deep" style={{ width: `${percent}%` }} />
      </span>
    </span>
  );
}
