import { getTranslations } from 'next-intl/server';

import { pricedOn } from '@/features/booking/departures';
import type { AppLocale } from '@/i18n/routing';
import { siteUrl } from '@/lib/content';
import type { PhotoView, SiteViews, TourPageView } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { localizedPath, tourPagePath, whatsappUrl } from '@/lib/links';
import { roomPercent, roomPricesFor } from '@/lib/package-price';

/** Midnight in Dhaka on a date: departures and the brochure's own date are Dhaka days. */
const dhakaDay = (iso: string) => Date.parse(`${iso}T00:00:00+06:00`);

// A4 portrait. The cover fills the first page; later pages have a margin. A block is kept whole only when it fits on a
// page — a longer one splits between its paragraphs, its heading always with what follows (docs/tour-landing-pages.md §7).
const PRINT_CSS = `
@page { size: A4; margin: 13mm 0 14mm; }
@page :first { margin: 0; }
html, body { background: #fff !important; }
.tp-cover { height: 296mm; break-after: page; }
.tp-keep { break-inside: avoid; }
.tp-head { break-after: avoid; }
.tp-text { orphans: 3; widows: 3; }
.tp-photo { break-inside: avoid; }
.tp-row { break-inside: avoid; }
`;

function PrintPhoto({ photo, maxHeightMm }: { photo: PhotoView; maxHeightMm: number }) {
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={photo.image.url}
      width={photo.width ?? undefined}
      height={photo.height ?? undefined}
      alt={photo.image.alt}
      className="tp-photo mx-auto block h-auto w-auto max-w-full rounded-14"
      style={{ maxHeight: `${maxHeightMm}mm` }}
    />
  );
}

/**
 * A landing page laid out for the printed brochure (docs/tour-landing-pages.md §7): the same words, photos and look as
 * on the website, for A4. The slideshow becomes every photo in a grid, the countdown a fixed date, Book Now a link.
 * Rendered by the server alone — the PDF renderer runs no scripts.
 */
export async function TourPrint({ page, views, locale, asOf }: { page: TourPageView; views: SiteViews; locale: AppLocale; asOf: string }) {
  const t = await getTranslations({ locale, namespace: 'tourPage' });
  const td = await getTranslations({ locale, namespace: 'detail' });
  const tb = await getTranslations({ locale, namespace: 'booking' });
  const f = formattersFor(locale);
  const { pkg, countdown } = page;
  const { settings, pricing } = views;
  const duration =
    pkg.durationNights !== null
      ? t('durationNights', { days: f.number(pkg.durationDays), nights: f.number(pkg.durationNights) })
      : t('durationDays', { days: f.number(pkg.durationDays) });
  const air = pkg.includesAirfare === true ? t('airIncluded') : pkg.includesAirfare === false ? t('landOnly') : t('airAsk');
  const pageUrl = siteUrl() + localizedPath(locale, tourPagePath(page.slug));
  const bookUrl = `${pageUrl}?${new URLSearchParams({ book: '1', ...(countdown ? { date: countdown.departsOn } : {}) })}`;
  const daysToGo = countdown ? Math.max(0, Math.round((dhakaDay(countdown.departsOn) - dhakaDay(asOf)) / 86_400_000)) : 0;
  const rooms = countdown ? roomPricesFor(pricedOn(pkg, countdown.departsOn), 2, pricing, null) : null;
  const twin = roomPercent(pkg, 'twin', pricing);
  const link = 'font-semibold text-blue underline decoration-blue/40 underline-offset-2';

  return (
    <article className="text-ink" data-testid="tour-print">
      <style>{PRINT_CSS}</style>

      {/* Cover: the banner photo on the whole first page. */}
      <header className="tp-cover relative flex items-end overflow-hidden bg-ink-deep">
        {page.hero ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={page.hero.image.url} alt="" className="absolute inset-0 size-full object-cover" />
        ) : null}
        <div aria-hidden className="absolute inset-0 bg-linear-to-t from-black/85 via-black/35 to-black/10" />
        <div className="absolute top-[14mm] left-[14mm] font-display text-16 font-bold text-white">{settings.brand}</div>
        <div className="relative flex w-full flex-col gap-3 px-[14mm] pb-[20mm] text-white">
          <span className="font-display text-label text-orange-light uppercase">
            {pkg.destinationLabel} · {duration}
          </span>
          <h1 className="font-display text-[34pt] leading-1.1 font-extrabold text-balance text-white">{page.title}</h1>
          <div className="flex flex-wrap gap-2">
            {[t('groupTour'), air, pkg.code].map((chip) => (
              <span key={chip} className="rounded-pill bg-white/15 px-3.5 py-1.5 text-13 font-semibold text-white ring-1 ring-white/30">
                {chip}
              </span>
            ))}
          </div>
          {countdown ? <span className="text-15 text-white/90">{t('nextDepartureOn', { date: f.dateRange(countdown.departsOn, countdown.returnsOn) })}</span> : null}
        </div>
      </header>

      <div className="flex flex-col gap-[9mm] px-[14mm]">
        <dl className="tp-keep grid grid-cols-4 gap-3">
          {[
            [t('duration'), duration],
            [t('destination'), pkg.destinationLabel],
            [t('nextDeparture'), countdown ? f.dateRange(countdown.departsOn, countdown.returnsOn) : t('soon')],
            [t('perPerson'), countdown ? f.bdt(countdown.price) : f.bdt(pkg.listPrice)],
          ].map(([label, value], i) => (
            <div key={label} className={`flex flex-col gap-1 rounded-14 px-4 py-3.5 ${i === 3 ? 'bg-orange-tint' : 'bg-paper-alt'}`}>
              <dt className="text-11 font-semibold text-muted uppercase">{label}</dt>
              <dd className={`m-0 font-display text-16 font-bold ${i === 3 ? 'text-orange-deep' : ''}`}>{value}</dd>
            </div>
          ))}
        </dl>

        {page.intro ? (
          <section className="flex flex-col gap-2">
            <h2 className="tp-head text-22 font-bold">{t('introHeading')}</h2>
            <p className="tp-text text-14 leading-1.75 whitespace-pre-line">{page.intro}</p>
          </section>
        ) : null}

        {/* The departure as of the brochure's date, and every date with its price. */}
        <section className="tp-keep flex flex-col gap-3 rounded-18 bg-ink-deep p-[7mm] text-white">
          <span className="font-display text-label text-orange-light uppercase">{t('departureEyebrow')}</span>
          {countdown ? (
            <p className="text-16 font-semibold" data-testid="tour-print-departs">
              {t('printDeparts', { dates: f.dateRange(countdown.departsOn, countdown.returnsOn), n: daysToGo, nText: f.number(daysToGo), asOf: f.date(asOf) })}
            </p>
          ) : (
            <p className="text-15">{t('noDates')}</p>
          )}
          {pkg.departures.length > 0 ? (
            <table className="w-full border-collapse text-14">
              <caption className="pb-1.5 text-left text-12 font-semibold text-white/75">{t('printDatesHeading')}</caption>
              <tbody>
                {pkg.departures.map((d) => (
                  <tr key={d.departsOn} className="border-t border-white/15">
                    <td className="py-1.5 font-semibold">{f.dateRange(d.departsOn, d.returnsOn)}</td>
                    <td className="py-1.5 text-right">
                      {f.bdt(d.price)} <span className="text-white/75">{t('printPerPerson')}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}
          <p className="text-13 text-white/85">
            {t('printBookLine')}{' '}
            <a href={bookUrl} className="font-semibold text-orange-light underline underline-offset-2">
              {pageUrl.replace(/^https?:\/\//, '')}
            </a>
          </p>
        </section>

        {page.days.length > 0 ? (
          <section className="flex flex-col gap-[7mm]">
            <h2 className="tp-head text-22 font-bold">{t('itineraryHeading')}</h2>
            {page.days.map((day) => (
              <section key={day.day} className="tp-keep flex flex-col gap-2.5" data-testid="tour-print-day">
                <div className="tp-head flex flex-col gap-1">
                  {/* Room for the Bangla numerals' full height: with a tight line they printed onto the page before. */}
                  <span className="pt-1 font-display text-[26pt] leading-1.25 font-extrabold text-blue">
                    {t('day')} {f.number(day.day)}
                  </span>
                  {day.title ? <h3 className="text-17 leading-1.3 font-bold">{day.title}</h3> : null}
                </div>
                {day.body ? <p className="tp-text text-14 leading-1.75 whitespace-pre-line text-ink-soft">{day.body}</p> : null}
                {day.photos.length > 0 ? (
                  <div className={`grid items-start gap-3 ${day.photos.length === 1 ? 'grid-cols-1' : day.photos.length === 2 ? 'grid-cols-2' : 'grid-cols-3'}`}>
                    {day.photos.map((photo) => (
                      <PrintPhoto key={photo.image.url} photo={photo} maxHeightMm={day.photos.length === 1 ? 105 : 70} />
                    ))}
                  </div>
                ) : null}
              </section>
            ))}
          </section>
        ) : null}

        {/* Every earlier-trip photo, in rows that keep each one's shape, captioned with where it was. */}
        {page.memories.length > 0 ? (
          <section className="flex flex-col gap-3">
            <h2 className="tp-head text-22 font-bold">{t('memoriesHeading')}</h2>
            <p className="tp-head text-13 text-muted">{t('memoriesNote')}</p>
            <div className="flex flex-wrap gap-3" data-testid="tour-print-memories">
              {page.memories.map((photo) => {
                const aspect = photo.width && photo.height ? photo.width / photo.height : 4 / 3;
                return (
                  <figure key={photo.image.url} className="tp-row m-0 flex flex-col gap-1" style={{ flexGrow: aspect, flexBasis: `${aspect * 42}mm` }}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={photo.image.url} alt={photo.caption} width={photo.width ?? undefined} height={photo.height ?? undefined} className="block h-auto w-full rounded-10" />
                    <figcaption className="text-11 leading-1.35 text-muted">
                      {photo.caption}
                      {photo.month ? ` · ${f.month(photo.month)}` : ''}
                    </figcaption>
                  </figure>
                );
              })}
              {/* The last row keeps its photos' size instead of stretching them across the page. */}
              <span aria-hidden style={{ flexGrow: 10, flexBasis: 0 }} />
            </div>
          </section>
        ) : null}

        <section className="grid grid-cols-2 items-start gap-4">
          {rooms && countdown ? (
            <div className="tp-keep flex flex-col gap-1.5 rounded-16 border border-hairline p-5">
              <h2 className="tp-head text-17 font-bold">{td('roomsHeading')}</h2>
              <p className="text-12 text-muted">{td('roomsForDate', { date: f.dateRange(countdown.departsOn, countdown.returnsOn) })}</p>
              {(
                [
                  ['triple', tb('roomTriple')],
                  ['twin', twin > 0 ? tb('roomTwinPlus', { percent: f.percent(twin) }) : tb('roomTwin')],
                  ['single', tb('roomSingle', { percent: f.percent(roomPercent(pkg, 'single', pricing)) })],
                ] as const
              ).map(([room, label]) => (
                <div key={room} className="flex items-baseline justify-between gap-3 border-b border-hairline py-1.5 text-14 last:border-b-0">
                  <span>{label}</span>
                  <span className="font-display font-extrabold">{f.bdt(rooms[room])}</span>
                </div>
              ))}
              <p className="text-11 text-muted">{td('groupNote')}</p>
            </div>
          ) : null}
          <div className="flex flex-col gap-4">
            {pkg.includes.length > 0 ? (
              <div className="rounded-16 border border-green-panel-line bg-green-panel p-5">
                <h2 className="tp-head text-16 font-bold text-green-deep">{td('includes')}</h2>
                <ul className="m-0 mt-2 flex list-none flex-col gap-1.5 p-0">
                  {pkg.includes.map((item) => (
                    <li key={item} className="tp-row flex items-start gap-2 text-13 leading-1.5">
                      <Mark kind="yes" />
                      {item}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            {pkg.excludes.length > 0 ? (
              <div className="rounded-16 border border-orange-line bg-orange-panel p-5">
                <h2 className="tp-head text-16 font-bold text-amber">{td('excludes')}</h2>
                <ul className="m-0 mt-2 flex list-none flex-col gap-1.5 p-0">
                  {pkg.excludes.map((item) => (
                    <li key={item} className="tp-row flex items-start gap-2 text-13 leading-1.5">
                      <Mark kind="no" />
                      {item}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </div>
        </section>

        {/* Book and contact: real links in the PDF, and printed for paper. */}
        <section className="tp-keep flex flex-col gap-2.5 rounded-18 bg-paper-alt p-[7mm]">
          <h2 className="tp-head text-20 font-bold">{t('contactHeading')}</h2>
          <a href={bookUrl} className="self-start rounded-pill bg-orange-deep px-5 py-2.5 text-15 font-bold text-white">
            {t('bookNow')}
          </a>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-14">
            <dt className="text-muted">{t('printWebsite')}</dt>
            <dd className="m-0">
              <a href={pageUrl} className={link}>
                {pageUrl.replace(/^https?:\/\//, '')}
              </a>
            </dd>
            {settings.contact.whatsapp ? (
              <>
                <dt className="text-muted">{t('printWhatsapp')}</dt>
                <dd className="m-0">
                  <a href={whatsappUrl(settings.contact.whatsapp, td('whatsappMessage', { title: page.title }))} className={link}>
                    {settings.contact.whatsapp}
                  </a>
                </dd>
              </>
            ) : null}
            {[settings.contact.phone, settings.contact.phoneAlt].filter(Boolean).map((phone) => (
              <PhoneRow key={phone} label={t('printPhone')} phone={phone} className={link} />
            ))}
            {settings.contact.email ? (
              <>
                <dt className="text-muted">{t('printEmail')}</dt>
                <dd className="m-0">
                  <a href={`mailto:${settings.contact.email}`} className={link}>
                    {settings.contact.email}
                  </a>
                </dd>
              </>
            ) : null}
          </dl>
          <p className="text-12 text-muted">{t('printAsOf', { asOf: f.date(asOf) })}</p>
        </section>
      </div>
    </article>
  );
}

/** Drawn, not a glyph: the brochure's fonts have no tick or cross, and the server has no font that does. */
function Mark({ kind }: { kind: 'yes' | 'no' }) {
  return kind === 'yes' ? (
    <span aria-hidden className="relative mt-[0.45em] inline-block size-3 shrink-0 rounded-full bg-green-tint">
      <span className="absolute top-[2px] left-[4px] h-[6px] w-[3.5px] rotate-45 border-r-2 border-b-2 border-green" />
    </span>
  ) : (
    <span aria-hidden className="relative mt-[0.45em] inline-block size-3 shrink-0 rounded-full bg-orange-tint">
      <span className="absolute top-[5px] left-[3px] h-[2px] w-[6px] bg-amber" />
    </span>
  );
}

function PhoneRow({ label, phone, className }: { label: string; phone: string; className: string }) {
  return (
    <>
      <dt className="text-muted">{label}</dt>
      <dd className="m-0">
        <a href={`tel:${phone.replace(/[^\d+]/g, '')}`} className={className}>
          {phone}
        </a>
      </dd>
    </>
  );
}
