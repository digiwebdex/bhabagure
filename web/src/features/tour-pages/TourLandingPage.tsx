import { getTranslations } from 'next-intl/server';

import { buttonClass } from '@/components/ui/button';
import { pricedOn } from '@/features/booking/departures';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import { TourPhotoSlideshow } from '@/features/tour-photos/TourPhotoSlideshow';
import type { AppLocale } from '@/i18n/routing';
import type { PhotoView, SiteViews, TourPageView } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { whatsappUrl } from '@/lib/links';
import { roomPercent, roomPricesFor } from '@/lib/package-price';

import { BookNow } from './BookNow';
import { Countdown } from './Countdown';

/** A photo at its own shape: no stretching, no cropping. From the API's own sizes, not the website's image optimiser. */
function Photo({ photo, sizes, className = '', priority = false }: { photo: PhotoView; sizes: string; className?: string; priority?: boolean }) {
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={photo.image.url}
      srcSet={photo.srcSet ?? undefined}
      sizes={sizes}
      width={photo.width ?? undefined}
      height={photo.height ?? undefined}
      alt={photo.image.alt}
      loading={priority ? 'eager' : 'lazy'}
      decoding="async"
      className={`h-auto w-full rounded-18 object-contain shadow-card ${className}`}
    />
  );
}

function Eyebrow({ children }: { children: React.ReactNode }) {
  return <span className="font-display text-label text-orange-deep uppercase">{children}</span>;
}

/**
 * A group tour's landing page (docs/tour-landing-pages.md): a long, photo-led story — banner, the tour in brief, the next
 * departure with a countdown, each day beside its own photos, earlier trips, prices and what is included — with Book
 * Now along the way. Every word, price and date is the package's; a section with nothing behind it is left out.
 */
export async function TourLandingPage({ page, views, locale }: { page: TourPageView; views: SiteViews; locale: AppLocale }) {
  const t = await getTranslations({ locale, namespace: 'tourPage' });
  const td = await getTranslations({ locale, namespace: 'detail' });
  const tb = await getTranslations({ locale, namespace: 'booking' });
  const tdl = await getTranslations({ locale, namespace: 'download' });
  const f = formattersFor(locale);
  const { pkg, countdown } = page;
  const { settings, pricing } = views;
  const duration =
    pkg.durationNights !== null
      ? t('durationNights', { days: f.number(pkg.durationDays), nights: f.number(pkg.durationNights) })
      : t('durationDays', { days: f.number(pkg.durationDays) });
  const air = pkg.includesAirfare === true ? t('airIncluded') : pkg.includesAirfare === false ? t('landOnly') : t('airAsk');
  const date = countdown?.departsOn ?? null;
  const book = (label = t('bookNow')) => <BookNow packageSlug={pkg.slug} departsOn={date} label={label} />;
  const brochure = (
    <DownloadButton
      path={`portal/downloads/packages/${pkg.slug}?${new URLSearchParams({ pax: '2', locale })}`}
      filename={`bhabaghure-${pkg.slug}.pdf`}
      label={tdl('brochure')}
      className={buttonClass('outlineInk', 'lg', 'px-5')}
      testId="tour-download-brochure"
    />
  );
  const whatsapp = settings.contact.whatsapp ? (
    <a href={whatsappUrl(settings.contact.whatsapp, td('whatsappMessage', { title: page.title }))} target="_blank" rel="noopener noreferrer" className={buttonClass('outlineDark', 'lg', 'px-5')}>
      {td('askWhatsapp')}
    </a>
  ) : null;
  const rooms = countdown ? roomPricesFor(pricedOn(pkg, countdown.departsOn), 2, pricing, null) : null;
  const twin = roomPercent(pkg, 'twin', pricing);

  return (
    <article className="flex flex-col" data-testid="tour-landing-page">
      {/* Banner: the tour's photo across the page, the title over its darkened foot. */}
      <header className="relative isolate flex min-h-[78svh] items-end overflow-hidden bg-ink-deep">
        {page.hero ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={page.hero.image.url} srcSet={page.hero.srcSet ?? undefined} sizes="100vw" alt="" loading="eager" className="absolute inset-0 -z-10 size-full object-cover" />
        ) : null}
        <div aria-hidden className="absolute inset-0 -z-10 bg-linear-to-t from-black/80 via-black/35 to-black/10" />
        <div className="mx-auto flex w-full max-w-site flex-col gap-4 px-section-x pt-32 pb-14 text-white">
          <span className="font-display text-label text-orange-light uppercase">
            {pkg.destinationLabel} · {duration}
          </span>
          <h1 className="max-w-4xl font-display text-fluid-30-42 leading-1.15 font-extrabold tracking-title text-balance text-white sm:text-hero">{page.title}</h1>
          <div className="flex flex-wrap gap-2">
            {[t('groupTour'), air, pkg.code].map((chip) => (
              <span key={chip} className="rounded-pill bg-white/15 px-3.5 py-1.5 text-13 font-semibold text-white ring-1 ring-white/25 backdrop-blur-sm">
                {chip}
              </span>
            ))}
          </div>
          <div className="mt-2 flex flex-wrap items-center gap-3">
            {book()}
            {countdown ? (
              <span className="text-15 text-white/90">
                {t('nextDepartureOn', { date: f.dateRange(countdown.departsOn, countdown.returnsOn) })}
              </span>
            ) : null}
          </div>
        </div>
      </header>

      <div className="mx-auto flex w-full max-w-site flex-col gap-fluid-28-44 px-section-x py-section-y">
        {/* The tour in brief. */}
        <dl className="grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="tour-facts">
          {[
            [t('duration'), duration],
            [t('destination'), pkg.destinationLabel],
            [t('nextDeparture'), countdown ? f.dateRange(countdown.departsOn, countdown.returnsOn) : t('soon')],
            [t('perPerson'), countdown ? f.bdt(countdown.price) : f.bdt(pkg.listPrice)],
          ].map(([label, value], i) => (
            <div key={label} className={`flex flex-col gap-1 rounded-16 px-4.5 py-4 ${i === 3 ? 'bg-orange-tint' : 'bg-paper-alt'}`}>
              <dt className="text-12 font-semibold text-muted uppercase">{label}</dt>
              <dd className={`m-0 font-display text-18 font-bold ${i === 3 ? 'text-orange-deep' : ''}`}>{value}</dd>
            </div>
          ))}
        </dl>

        {page.intro ? (
          <section className="flex max-w-3xl flex-col gap-3" data-testid="tour-intro">
            <Eyebrow>{t('introEyebrow')}</Eyebrow>
            <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('introHeading')}</h2>
            <p className="text-16 leading-1.75 whitespace-pre-line text-pretty">{page.intro}</p>
          </section>
        ) : null}

        {/* The next departure and the time left to it; every other date beside it. */}
        <section className="grid gap-5 rounded-22 bg-ink-deep p-fluid-20-32 text-white md:grid-cols-[1.2fr_1fr]" data-testid="tour-departure">
          <div className="flex flex-col gap-3">
            <span className="font-display text-label text-orange-light uppercase">{t('departureEyebrow')}</span>
            {countdown ? (
              <>
                <h2 className="font-display text-fluid-20-26 font-bold text-white">{f.dateRange(countdown.departsOn, countdown.returnsOn)}</h2>
                <p className="text-15 text-white/85">
                  {tb('departurePrice', { price: f.bdt(countdown.price) })} ·{' '}
                  {countdown.seatsLeft === 0 ? tb('soldOut') : tb('seatsLeft', { seats: countdown.seatsLeft, seatsText: f.number(countdown.seatsLeft) })}
                </p>
                <span className="text-13 text-white/75">{t('countdownLabel')}</span>
                <Countdown departsOn={countdown.departsOn} tone="dark" />
                <div className="mt-1">{book(t('bookThisDate'))}</div>
              </>
            ) : (
              <p className="text-15 text-white/85">{t('noDates')}</p>
            )}
          </div>
          {pkg.departures.length > 1 ? (
            <div className="flex flex-col gap-2">
              <span className="text-13 font-semibold text-white/75">{t('allDates')}</span>
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {pkg.departures.map((d) => (
                  <li key={d.departsOn} className="flex flex-wrap items-center justify-between gap-2 rounded-14 bg-white/8 px-4 py-3 ring-1 ring-white/15">
                    <span className="flex flex-col">
                      <span className="font-semibold">{f.dateRange(d.departsOn, d.returnsOn)}</span>
                      <span className="text-12 text-white/75">
                        {tb('departurePrice', { price: f.bdt(d.price) })} · {d.seatsLeft === 0 ? tb('soldOut') : tb('seatsLeft', { seats: d.seatsLeft, seatsText: f.number(d.seatsLeft) })}
                      </span>
                    </span>
                    {d.seatsLeft > 0 ? <BookNow packageSlug={pkg.slug} departsOn={d.departsOn} label={t('book')} size="sm" /> : null}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>

        {/* Day by day: a large heading, the day as written on the package, and the photos staff put beside it. */}
        {page.days.length > 0 ? (
          <section className="flex flex-col gap-fluid-28-44" data-testid="tour-itinerary">
            <div className="flex flex-col gap-1.5">
              <Eyebrow>{duration}</Eyebrow>
              <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('itineraryHeading')}</h2>
            </div>
            <ol className="m-0 flex list-none flex-col gap-fluid-28-44 p-0">
              {page.days.map((day, i) => (
                <li key={day.day} data-testid="tour-day" className={`grid items-center gap-6 ${day.photos.length > 0 ? 'md:grid-cols-2' : ''}`}>
                  <div className={`flex flex-col gap-2.5 ${day.photos.length > 0 && i % 2 === 1 ? 'md:order-2' : ''}`}>
                    <span className="font-display text-fluid-30-42 leading-1 font-extrabold tracking-title text-blue">
                      {t('day')} {f.number(day.day)}
                    </span>
                    {day.title ? <h3 className="text-fluid-19-24 leading-1.3 font-bold">{day.title}</h3> : null}
                    {day.body ? <p className="text-15 leading-1.75 whitespace-pre-line text-muted text-pretty">{day.body}</p> : null}
                  </div>
                  {/* One photo large; two side by side; three as one large and two beneath. */}
                  {day.photos.length === 2 ? (
                    <div className="grid grid-cols-2 items-start gap-3">
                      {day.photos.map((photo) => (
                        <Photo key={photo.image.url} photo={photo} sizes="(min-width: 768px) 25vw, 50vw" />
                      ))}
                    </div>
                  ) : day.photos.length > 0 ? (
                    <div className="flex flex-col gap-3">
                      <Photo photo={day.photos[0]} sizes="(min-width: 768px) 50vw, 100vw" />
                      {day.photos.length > 1 ? (
                        <div className="grid grid-cols-2 items-start gap-3">
                          {day.photos.slice(1).map((photo) => (
                            <Photo key={photo.image.url} photo={photo} sizes="(min-width: 768px) 25vw, 50vw" />
                          ))}
                        </div>
                      ) : null}
                    </div>
                  ) : null}
                </li>
              ))}
            </ol>
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-20 bg-orange-tint px-fluid-20-28 py-5">
              <span className="text-fluid-19-22 font-bold">{t('readyHeading')}</span>
              {book()}
            </div>
          </section>
        ) : null}

        {/* Earlier trips, each captioned with where it was: not the days above (docs/tour-landing-pages.md). */}
        {page.memories.length > 0 ? (
          <section className="flex flex-col gap-4" data-testid="tour-memories">
            <div className="flex flex-col gap-1.5">
              <Eyebrow>{t('memoriesEyebrow')}</Eyebrow>
              <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('memoriesHeading')}</h2>
              <p className="text-14 text-muted">{t('memoriesNote')}</p>
            </div>
            <TourPhotoSlideshow photos={page.memories} linkToTour={false} />
          </section>
        ) : null}

        {/* Prices and what is included. */}
        <section className="grid gap-4.5 md:grid-cols-3" data-testid="tour-prices">
          {rooms && countdown ? (
            <div className="flex flex-col gap-2 rounded-18 border border-hairline bg-white p-5">
              <Eyebrow>{t('pricesEyebrow')}</Eyebrow>
              <h2 className="text-19 font-bold">{td('roomsHeading')}</h2>
              <p className="-mt-1 text-12 text-muted">{td('roomsForDate', { date: f.dateRange(countdown.departsOn, countdown.returnsOn) })}</p>
              <ul className="m-0 flex list-none flex-col p-0">
                {(
                  [
                    ['triple', tb('roomTriple')],
                    ['twin', twin > 0 ? tb('roomTwinPlus', { percent: f.percent(twin) }) : tb('roomTwin')],
                    ['single', tb('roomSingle', { percent: f.percent(roomPercent(pkg, 'single', pricing)) })],
                  ] as const
                ).map(([room, label]) => (
                  <li key={room} className="flex items-baseline justify-between gap-3 border-b border-hairline py-2 text-14 last:border-b-0">
                    <span>{label}</span>
                    <span className="font-display text-16 font-extrabold">{f.bdt(rooms[room])}</span>
                  </li>
                ))}
              </ul>
              <p className="text-12 text-muted">{td('groupNote')}</p>
            </div>
          ) : null}
          {pkg.includes.length > 0 ? (
            <div className="flex flex-col gap-2.25 rounded-18 border border-green-panel-line bg-green-panel p-5">
              <h2 className="text-16 font-bold text-green-deep">{td('includes')}</h2>
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {pkg.includes.map((item) => (
                  <li key={item} className="flex items-start gap-2.25 text-14 leading-1.55">
                    <span aria-hidden className="shrink-0 font-bold text-green">✓</span>
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
          {pkg.excludes.length > 0 ? (
            <div className="flex flex-col gap-2.25 rounded-18 border border-orange-line bg-orange-panel p-5">
              <h2 className="text-16 font-bold text-amber">{td('excludes')}</h2>
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {pkg.excludes.map((item) => (
                  <li key={item} className="flex items-start gap-2.25 text-14 leading-1.55">
                    <span aria-hidden className="shrink-0 font-bold text-amber">✕</span>
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>

        {/* Closing: book, ask, call, or take the brochure. */}
        <section className="flex flex-col gap-4 rounded-22 bg-paper-alt p-fluid-20-32" data-testid="tour-contact">
          <h2 className="text-fluid-20-26 font-bold tracking-heading">{t('contactHeading')}</h2>
          <p className="text-15 text-muted">{t('contactLede')}</p>
          <div className="flex flex-wrap items-center gap-3">
            {book()}
            {whatsapp}
            {brochure}
          </div>
          <p className="text-14 text-muted">
            {[settings.contact.phone, settings.contact.phoneAlt, settings.contact.email].filter(Boolean).join(' · ')}
          </p>
        </section>
      </div>
    </article>
  );
}
