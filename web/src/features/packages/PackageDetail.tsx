'use client';

import { useLocale, useTranslations } from 'next-intl';

import { defaultHotelCategory, packagePerPerson } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Stepper } from '@/components/ui/Stepper';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import type { PackageView } from '@/lib/content/views';
import { whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking } from '@/state/booking';
import { useSiteUi } from '@/state/site-ui';
import { useTripSearch } from '@/state/trip-search';

import { packageLabels } from './package-labels';
import { PhotoGallery } from './PhotoGallery';

/** Group-size chips shown above the stepper: 1 · 2 · 4 · 6 · 10+. */
const SLAB_CHIPS = [1, 2, 4, 6, 10] as const;

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
  const labels = packageLabels(pkg, tp, f);

  // A grid package: pick the hotel category first, then the group size (Phase 8 §4.D).
  const grid = pkg.hotelCategories.length > 0 ? pkg.priceGrid : null;
  const category = grid ? (chosenCategory && pkg.hotelCategories.includes(chosenCategory) ? chosenCategory : defaultHotelCategory(grid)) : null;
  const rate = (travellers: number) => packagePerPerson(pkg, travellers, pricing.slabs, category);
  const perPerson = rate(pax);
  const total = perPerson * pax;
  const paxText = f.number(pax);

  const slabNote = category
    ? pax === 1
      ? t('gridNoteOne', { category: t(`hotelCategories.${category}`) })
      : t('gridNote', { category: t(`hotelCategories.${category}`), percent: f.percent(pricing.singleRoomSupplementPercent) })
    : pax === 1
      ? t('slabNoteOne', { percent: f.percent(pricing.singleRoomSupplementPercent) })
      : pax === 2
        ? t('slabNoteTwo')
        : t('slabNoteGroup', { paxText });

  // A chip is "current" when the stepper sits inside its tier's range.
  const activeChip = [...SLAB_CHIPS].reverse().find((min) => pax >= min && (min !== 2 || pax === 2) && (min !== 1 || pax === 1));

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
        <div className="flex flex-wrap gap-1.75">
          {SLAB_CHIPS.map((min, i) => {
            const active = activeChip === min;
            const label = i === SLAB_CHIPS.length - 1 ? t('slabChipPlus', { paxText: f.number(min) }) : t('slabChip', { pax: min, paxText: f.number(min) });
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

/** Footer row: hint, WhatsApp enquiry and Book. */
export function PackageDetailActions({ pkg, onBeforeBook }: { pkg: PackageView; onBeforeBook?: () => void }) {
  const t = useTranslations('detail');
  const tp = useTranslations('packages');
  const td = useTranslations('download');
  const locale = useLocale();
  const { settings, pricing } = useSiteContent();
  const pax = useSiteUi((state) => state.detailPax);
  const detailCategory = useSiteUi((state) => state.detailCategory);
  const startBooking = useBooking((state) => state.start);
  // The brochure highlights what is chosen here; the API picks basic/3-star when no category was picked.
  const query = new URLSearchParams({ pax: String(pax), locale, ...(pkg.priceGrid && detailCategory ? { hotel_category: detailCategory } : {}) });

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
          onClick={() => {
            onBeforeBook?.();
            // The booking starts in the hotel category chosen here.
            startBooking({ packageSlug: pkg.slug, pax, date: useTripSearch.getState().date, maxPax: pricing.maxTravellers, hotelCategory: detailCategory });
          }}
          className={buttonClass('cta', 'lg', 'shadow-cta')}
        >
          {tp('book')}
        </button>
      </div>
    </>
  );
}
