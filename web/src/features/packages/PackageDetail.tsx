'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useEffect, useId, useRef } from 'react';

import { defaultHotelCategory, type HotelCategory } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Stepper } from '@/components/ui/Stepper';
import { pricedOn } from '@/features/booking/departures';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import type { PackageDepartureView, PackageView } from '@/lib/content/views';
import { pickedDeparture } from '@/lib/departures';
import { basePrice, roomPercent, roomPricesFor } from '@/lib/package-price';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking } from '@/state/booking';
import { useSiteUi } from '@/state/site-ui';
import { useTripSearch } from '@/state/trip-search';

import { activeSize, groupSizes } from './group-sizes';
import { packageLabels } from './package-labels';
import { PhotoGallery } from './PhotoGallery';
import { PriceTable } from './PriceTable';

/** Eyebrow and title — the modal puts these in its header, the package page above the body. */
export function PackageDetailHeading({ pkg, as: Heading = 'h2', id }: { pkg: PackageView; as?: 'h1' | 'h2'; id?: string }) {
  const t = useTranslations('packages');
  const f = useFormatters();
  const labels = packageLabels(pkg, t, f);
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <span className="font-display text-label text-orange-deep uppercase">
        {pkg.destinationLabel} · {labels.duration}
      </span>
      <Heading id={id} className="text-fluid-19-26 leading-1.25 font-bold tracking-title text-pretty">
        {pkg.title}
      </Heading>
    </div>
  );
}

/** Gallery, live slab pricing, itinerary timeline and inclusions. Shared by the modal and the package page. */
export function PackageDetailBody({ pkg }: { pkg: PackageView }) {
  const t = useTranslations('detail');
  const tp = useTranslations('packages');
  const f = useFormatters();
  const { pricing } = useSiteContent();
  const pax = useSiteUi((state) => state.detailPax);
  const increase = useSiteUi((state) => state.increaseDetailPax);
  const decrease = useSiteUi((state) => state.decreaseDetailPax);
  const setPax = useSiteUi((state) => state.setDetailPax);
  const chosenCategory = useSiteUi((state) => state.detailCategory);
  const setCategory = useSiteUi((state) => state.setDetailCategory);
  // A group tour is shown on one departure: the date picked here, else the card's; its price is the page's
  // (docs/departure-prices.md).
  const departure = usePickedDeparture(pkg);
  const priced = departure ? pricedOn(pkg, departure.departsOn) : pkg;
  const labels = packageLabels(pkg, tp, f, departure?.departsOn ?? null);

  // A grid package: pick the hotel category first, then the group size (Phase 8 §4.D).
  const grid = pkg.hotelCategories.length > 0 ? pkg.priceGrid : null;
  const category = grid ? (chosenCategory && pkg.hotelCategories.includes(chosenCategory) ? chosenCategory : defaultHotelCategory(grid)) : null;
  const rate = (travellers: number) => basePrice(priced, travellers, pricing.slabs, category);
  const perPerson = rate(pax);
  const total = perPerson * pax;
  const paxText = f.number(pax);

  const slabNote = pkg.groupTour
    ? t('groupNote')
    : category
    ? pax === 1
      ? t('gridNoteOne', { category: t(`hotelCategories.${category}`) })
      : t('gridNote', { category: t(`hotelCategories.${category}`), percent: f.percent(roomPercent(pkg, 'single', pricing)) })
    : pax === 1
      ? t('slabNoteOne', { percent: f.percent(roomPercent(pkg, 'single', pricing)) })
      : pax === 2
        ? t('slabNoteTwo')
        : t('slabNoteGroup', { paxText });

  // Group-size chips above the stepper: the price table's sizes, else the discount steps (docs/customized-trip-group-sizes.md).
  const chips = groupSizes(pkg);
  const activeChip = activeSize(pkg, chips, pax);

  return (
    <div className="flex flex-col gap-6.5">
      <PhotoGallery images={pkg.images} title={pkg.title} />

      <div className="flex flex-wrap items-end justify-between gap-3.5 rounded-16 bg-paper-alt px-5 py-4.5">
        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="text-12 text-muted">{t('perPerson')}</span>
          <span className="font-display text-34 leading-1 font-extrabold tracking-price text-orange-deep">{f.bdt(perPerson)}</span>
          <span className="text-12 text-muted">{t('headNote', { pax, paxText, total: f.bdt(total) })}</span>
        </div>
        <div className="flex flex-wrap gap-2">
          {[labels.air, labels.groupSize, labels.departure].map((chip) => (
            <span key={chip} className="rounded-9 border border-hairline bg-white px-3 py-1.5 text-13 whitespace-nowrap">
              {chip}
            </span>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-3.25">
        <div className="flex flex-wrap items-baseline justify-between gap-2.5">
          <h3 className="text-19 font-bold tracking-heading">{t('pricing')}</h3>
          <span aria-live="polite" className="text-13 text-muted">
            {slabNote}
          </span>
        </div>
        {category ? (
          <div role="radiogroup" aria-label={t('hotelCategory')} className="flex flex-wrap items-center gap-1.75" data-testid="hotel-categories">
            <span className="mr-1 text-13 font-semibold text-muted">{t('hotelCategory')}</span>
            {pkg.hotelCategories.map((option) => (
              <button
                key={option}
                type="button"
                role="radio"
                aria-checked={option === category}
                onClick={() => setCategory(option)}
                className={`cursor-pointer rounded-pill border-chip px-4 py-2 text-14 font-semibold ${
                  option === category ? 'border-orange-deep bg-orange-tint text-orange-deep' : 'border-hairline bg-white text-ink'
                }`}
              >
                {t(`hotelCategories.${option}`)}
              </button>
            ))}
          </div>
        ) : null}
        {pkg.groupTour ? (
          <GroupTourPrices pkg={pkg} pax={pax} departure={departure} />
        ) : (
        <div className="flex flex-wrap gap-1.75">
          {chips.map((min, i) => {
            const active = activeChip === min;
            const label = i === chips.length - 1 ? t('slabChipPlus', { paxText: f.number(min) }) : t('slabChip', { pax: min, paxText: f.number(min) });
            return (
              <button
                key={min}
                type="button"
                aria-pressed={active}
                onClick={() => setPax(min, pricing.maxTravellers)}
                className={`flex cursor-pointer flex-col items-start gap-0.5 rounded-12 border-chip px-3.75 py-2.25 text-left ${
                  active ? 'border-blue bg-blue-tint text-blue-deep' : 'border-hairline bg-white text-ink'
                }`}
              >
                <span className="text-12 opacity-75">{label}</span>
                <span className="font-display text-15 font-extrabold tracking-heading">{f.bdt(rate(min))}</span>
              </button>
            );
          })}
        </div>
        )}
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-16 border border-hairline bg-paper-alt px-4.5 py-4">
          <div className="flex flex-col gap-1.5 text-12 font-semibold text-muted">
            {t('travellers')}
            <Stepper
              size="detail"
              value={pax}
              display={paxText}
              max={pricing.maxTravellers}
              onDecrease={decrease}
              onIncrease={() => increase(pricing.maxTravellers)}
              decreaseLabel={t('travellers') + ' −'}
              increaseLabel={t('travellers') + ' +'}
            />
          </div>
          <span className="flex flex-col gap-px">
            <span className="text-12 text-muted">{t('perPerson')}</span>
            <span className="font-display text-22 font-extrabold tracking-price-sm">{f.bdt(perPerson)}</span>
          </span>
          <span className="flex flex-col gap-px">
            <span className="text-12 text-muted">{t('groupTotal')}</span>
            <span className="font-display text-26 font-extrabold tracking-price text-orange-deep">{f.bdt(total)}</span>
          </span>
        </div>

        {pkg.groupTour ? null : (
          <div className="flex flex-col gap-1 rounded-16 border border-hairline bg-white p-4" data-testid="room-prices">
            <h4 className="text-15 font-bold">{t('roomsFor', { pax, paxText })}</h4>
            <RoomPriceList pkg={pkg} pax={pax} category={category} />
          </div>
        )}
        {pkg.groupTour ? null : <PriceTable pkg={pkg} rate={rate} />}
      </div>

      {pkg.itinerary.length > 0 ? (
        <div className="flex flex-col gap-3.5">
          <h3 className="text-19 font-bold tracking-heading">{t('itinerary')}</h3>
          <ol className="flex flex-col">
            {pkg.itinerary.map((day) => (
              <li key={day.day} className="flex items-start gap-3.75">
                <span aria-hidden className="flex w-11.5 shrink-0 flex-col items-center gap-1.25 self-stretch">
                  <span className="flex size-11.5 flex-col items-center justify-center rounded-13 bg-blue leading-1 text-white">
                    <span className="mb-px font-display text-8 tracking-label opacity-70">{t('day')}</span>
                    <span className="font-sans text-17 font-bold">{f.number(day.day)}</span>
                  </span>
                  <span className="min-h-2.5 w-0.5 flex-1 bg-hairline" />
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-1 pb-3.5">
                  <span className="sr-only">
                    {t('day')} {f.number(day.day)}:
                  </span>
                  <span className="text-16 leading-1.3 font-semibold">{day.title}</span>
                  <span className="text-14 leading-1.65 text-muted text-pretty">{day.body}</span>
                </span>
              </li>
            ))}
          </ol>
        </div>
      ) : null}

      {pkg.includes.length > 0 || pkg.excludes.length > 0 ? (
        <div className="grid-auto-fit-250 grid gap-4.5">
          <div className="flex flex-col gap-2.25 rounded-16 border border-green-panel-line bg-green-panel p-4.5">
            <h3 className="text-16 font-bold text-green-deep">{t('includes')}</h3>
            <ul className="flex flex-col gap-2.25">
              {pkg.includes.map((item) => (
                <li key={item} className="flex items-start gap-2.25 text-14 leading-1.55">
                  <span aria-hidden className="shrink-0 font-bold text-green">
                    ✓
                  </span>
                  {item}
                </li>
              ))}
            </ul>
          </div>
          <div className="flex flex-col gap-2.25 rounded-16 border border-orange-line bg-orange-panel p-4.5">
            <h3 className="text-16 font-bold text-amber">{t('excludes')}</h3>
            <ul className="flex flex-col gap-2.25">
              {pkg.excludes.map((item) => (
                <li key={item} className="flex items-start gap-2.25 text-14 leading-1.55">
                  <span aria-hidden className="shrink-0 font-bold text-amber">
                    ✕
                  </span>
                  {item}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </div>
  );
}

/**
 * What each room costs one traveller (docs/room-rates.md): triple sharing is the base, twin and single add the
 * package's percentages — priced for these travellers exactly as the booking will be.
 */
function RoomPriceList({ pkg, pax, category }: { pkg: PackageView; pax: number; category: HotelCategory | null }) {
  const tb = useTranslations('booking');
  const f = useFormatters();
  const { pricing } = useSiteContent();
  const prices = roomPricesFor(pkg, pax, pricing, category);
  const twin = roomPercent(pkg, 'twin', pricing);
  const rooms = [
    { room: 'triple', label: tb('roomTriple') },
    { room: 'twin', label: twin > 0 ? tb('roomTwinPlus', { percent: f.percent(twin) }) : tb('roomTwin') },
    { room: 'single', label: tb('roomSingle', { percent: f.percent(roomPercent(pkg, 'single', pricing)) }) },
  ] as const;

  return (
    <ul className="flex flex-col">
      {rooms.map(({ room, label }) => (
        <li key={room} className="flex items-baseline justify-between gap-3 border-b border-hairline py-2 text-14 last:border-b-0">
          <span>{label}</span>
          <span className="font-display text-16 font-extrabold tracking-heading">{f.bdt(prices[room])}</span>
        </li>
      ))}
    </ul>
  );
}

/** The departure a group tour's page or modal shows: the date picked there while it has seats, else the card's. */
function usePickedDeparture(pkg: PackageView): PackageDepartureView | undefined {
  const picked = useSiteUi((state) => (state.detailDeparture?.slug === pkg.slug ? state.detailDeparture.departsOn : null));
  return pkg.groupTour ? pickedDeparture(pkg.departures, picked) : undefined;
}

/**
 * A fixed-departure group tour (docs/fixed-departure-group-tours.md): what each room costs one traveller, and the
 * dates the group leaves on — the only dates it is booked on. Each date is a choice at its own price (client,
 * 2026-10-01; docs/departure-prices.md): picking one prices the rooms and the page for it, and Book starts on it.
 */
function GroupTourPrices({ pkg, pax, departure }: { pkg: PackageView; pax: number; departure: PackageDepartureView | undefined }) {
  const t = useTranslations('detail');
  const tb = useTranslations('booking');
  const f = useFormatters();
  const pick = useSiteUi((state) => state.setDetailDeparture);
  const headingId = useId();

  return (
    <div className="grid-auto-fit-250 grid gap-3.5" data-testid="group-tour-prices">
      <div className="flex flex-col gap-2 rounded-16 border border-hairline bg-white p-4">
        <h4 className="text-15 font-bold">{t('roomsHeading')}</h4>
        {departure ? <p className="-mt-1 text-12 text-muted">{t('roomsForDate', { date: f.dateRange(departure.departsOn, departure.returnsOn) })}</p> : null}
        <RoomPriceList pkg={departure ? pricedOn(pkg, departure.departsOn) : pkg} pax={pax} category={null} />
      </div>
      <div className="flex flex-col gap-2 rounded-16 border border-hairline bg-white p-4">
        <h4 id={headingId} className="text-15 font-bold">
          {t('departuresHeading')}
        </h4>
        {pkg.departures.length === 0 ? (
          <p className="text-14 text-muted">{tb('noDeparturesYet')}</p>
        ) : (
          <>
            {pkg.departures.length > 1 ? <p className="-mt-1 text-12 text-muted">{t('departuresPick')}</p> : null}
            <div role="radiogroup" aria-labelledby={headingId} className="flex flex-col gap-1.75" data-testid="departure-picker">
              {pkg.departures.map((d) => {
                const full = d.seatsLeft === 0;
                const on = d.departsOn === departure?.departsOn;
                return (
                  <button
                    key={d.departsOn}
                    type="button"
                    role="radio"
                    aria-checked={on}
                    aria-disabled={full}
                    onClick={() => (full ? undefined : pick(pkg.slug, d.departsOn))}
                    className={`flex items-center justify-between gap-3 rounded-12 border-chip px-3.5 py-2.5 text-left text-14 ${
                      on ? 'border-orange-deep bg-orange-tint' : 'border-hairline bg-white'
                    } ${full ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-orange-deep'}`}
                  >
                    <span className="flex min-w-0 flex-col">
                      <span className={`font-semibold ${on ? 'text-orange-deep' : 'text-ink'}`}>{f.dateRange(d.departsOn, d.returnsOn)}</span>
                      <span className="text-12 text-muted">{tb('departurePrice', { price: f.bdt(d.price) })}</span>
                    </span>
                    <span className={`shrink-0 text-13 ${full ? 'font-semibold text-red' : 'text-muted'}`}>
                      {full ? tb('soldOut') : tb('seatsLeft', { seats: d.seatsLeft, seatsText: f.number(d.seatsLeft) })}
                    </span>
                  </button>
                );
              })}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/** Footer row: hint, WhatsApp enquiry and Book. */
export function PackageDetailActions({ pkg, onBeforeBook, bookFromLink = false }: { pkg: PackageView; onBeforeBook?: () => void; bookFromLink?: boolean }) {
  const t = useTranslations('detail');
  const tp = useTranslations('packages');
  const td = useTranslations('download');
  const locale = useLocale();
  const { settings, pricing } = useSiteContent();
  const pax = useSiteUi((state) => state.detailPax);
  const detailCategory = useSiteUi((state) => state.detailCategory);
  const startBooking = useBooking((state) => state.start);
  const departure = usePickedDeparture(pkg);
  // The brochure highlights what is chosen here; the API picks basic/3-star when no category was picked.
  const query = new URLSearchParams({ pax: String(pax), locale, ...(pkg.priceGrid && detailCategory ? { hotel_category: detailCategory } : {}) });
  const book = () => {
    onBeforeBook?.();
    // The booking starts in the hotel category chosen here, and a group tour on the departure shown here.
    const date = departure ? departure.departsOn : useTripSearch.getState().date;
    startBooking({ packageSlug: pkg.slug, pax, date, maxPax: pricing.maxTravellers, hotelCategory: detailCategory });
  };

  // The package's own page, reached by a "Book" link (the customer portal's "Book another trip", 2026-10-03): the
  // booking form opens at once, and the address loses `book=1` so a reload or a shared link shows just the page.
  const bookedFromLink = useRef(false);
  useEffect(() => {
    if (!bookFromLink || bookedFromLink.current) return;
    const url = new URL(window.location.href);
    if (!url.searchParams.has('book')) return;
    bookedFromLink.current = true;
    url.searchParams.delete('book');
    window.history.replaceState(window.history.state, '', url);
    book();
  });

  return (
    <>
      <span className="text-13 text-muted">{t('foot')}</span>
      <div className="flex flex-wrap items-start gap-2.5">
        <DownloadButton
          path={`portal/downloads/packages/${pkg.slug}?${query}`}
          filename={`bhabaghure-${pkg.slug}.pdf`}
          label={td('brochure')}
          className={buttonClass('outlineInk', 'lg', 'px-5')}
          testId="download-brochure"
        />
        <a
          href={whatsappUrl(settings.contact.whatsapp, t('whatsappMessage', { title: pkg.title }))}
          target="_blank"
          rel="noopener noreferrer"
          className={buttonClass('outlineDark', 'lg', 'px-5')}
        >
          {t('askWhatsapp')}
        </a>
        <button
          type="button"
          onClick={book}
          className={buttonClass('cta', 'lg', 'shadow-cta')}
        >
          {tp('book')}
        </button>
      </div>
    </>
  );
}
