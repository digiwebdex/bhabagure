'use client';

import { useTranslations } from 'next-intl';

import { defaultHotelCategory, type HotelCategory, type RoomType } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { controlClass, Field } from '@/components/ui/Field';
import { activeSize, groupSizes } from '@/features/packages/group-sizes';
import type { PackageDepartureView, PackageView } from '@/lib/content/views';
import { basePrice } from '@/lib/package-price';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeDigits, todayIso } from '@/lib/validators';
import { useBooking } from '@/state/booking';

import { bookingDate, hasRoom } from './departures';
import type { PackageStepErrors } from './validation';

const ROOMS: RoomType[] = ['twin', 'single', 'triple'];

/**
 * A fixed-departure group tour's date (docs/fixed-departure-group-tours.md): never a calendar. One departure is shown
 * as fixed text; several are a short list, where a departure without room for everyone can't be picked.
 */
function GroupTourDate({ pkg, error }: { pkg: PackageView; error?: string }) {
  const t = useTranslations('booking');
  const f = useFormatters();
  const booking = useBooking();
  const chosen = bookingDate(pkg, booking.date, booking.pax);
  const seats = (d: PackageDepartureView) =>
    d.seatsLeft === 0
      ? t('soldOut')
      : hasRoom(d, booking.pax)
        ? t('seatsLeft', { seats: d.seatsLeft, seatsText: f.number(d.seatsLeft) })
        : t('fewSeats', { seatsText: f.number(d.seatsLeft) });

  if (pkg.departures.length === 0) {
    return (
      <div className="flex flex-col gap-1.5 text-14 text-muted" data-testid="group-tour-date">
        {t('departureFixed')}
        <p role="alert" className="rounded-10 bg-orange-tint px-3 py-2.5 text-13 font-semibold text-amber">
          {t('noDeparturesYet')}
        </p>
      </div>
    );
  }

  if (pkg.departures.length === 1) {
    const only = pkg.departures[0];
    return (
      <div className="flex flex-col gap-1.5 text-14 text-muted" data-testid="group-tour-date">
        {t('departureFixed')}
        <p className="flex flex-col rounded-10 border border-input bg-paper-alt p-3 text-15 text-ink">
          <strong className="font-semibold">{f.date(only.departsOn)}</strong>
          <span className="text-12 text-muted">{seats(only)}</span>
        </p>
        {error ? (
          <span role="alert" className="text-12 font-semibold text-red">
            {error}
          </span>
        ) : null}
      </div>
    );
  }

  return (
    <Field variant="form" label={t('departurePick')} error={error}>
      <select value={chosen} onChange={(e) => booking.setDate(e.target.value)} className={controlClass(!!error, 'form')} data-testid="group-tour-date">
        <option value="" disabled>
          {t('departurePick')}
        </option>
        {pkg.departures.map((d) => (
          <option key={d.departsOn} value={d.departsOn} disabled={!hasRoom(d, booking.pax)}>
            {f.date(d.departsOn)} · {seats(d)}
          </option>
        ))}
      </select>
    </Field>
  );
}

/**
 * A customized trip is priced by how many travel (docs/customized-trip-group-sizes.md): each group size with its price
 * per person, picked before anything else. Other counts go in the travellers box and pay the smaller size's price.
 */
function GroupSizePicker({ pkg }: { pkg: PackageView }) {
  const t = useTranslations('booking');
  const td = useTranslations('detail');
  const f = useFormatters();
  const { pricing } = useSiteContent();
  const booking = useBooking();
  const category = pkg.hotelCategories.length > 0 ? (booking.hotelCategory && pkg.hotelCategories.includes(booking.hotelCategory) ? booking.hotelCategory : defaultHotelCategory(pkg.priceGrid)) : null;
  const sizes = groupSizes(pkg).filter((size) => size <= pricing.maxTravellers);
  const current = activeSize(pkg, sizes, booking.pax);

  return (
    <fieldset className="m-0 flex flex-col gap-2 border-0 p-0" data-testid="group-sizes">
      <legend className="mb-1 text-14 font-semibold text-ink">{t('sizesHeading')}</legend>
      <div className="flex flex-wrap gap-1.75">
        {sizes.map((size, i) => {
          const active = size === current;
          return (
            <button
              key={size}
              type="button"
              aria-pressed={active}
              onClick={() => booking.setPax(size, pricing.maxTravellers)}
              className={`flex cursor-pointer flex-col items-start gap-0.5 rounded-12 border-chip px-3.5 py-2 text-left ${active ? 'border-blue bg-blue-tint text-blue-deep' : 'border-hairline bg-white text-ink'}`}
            >
              <span className="text-12 opacity-75">{i === sizes.length - 1 ? td('slabChipPlus', { paxText: f.number(size) }) : td('slabChip', { pax: size, paxText: f.number(size) })}</span>
              <span className="font-display text-15 font-extrabold tracking-heading">{f.bdt(basePrice(pkg, size, pricing.slabs, category))}</span>
            </button>
          );
        })}
      </div>
      <span className="text-12 text-muted">{t('sizesNote')}</span>
    </fieldset>
  );
}

export function PackageStep({ errors, roomLabel }: { errors: PackageStepErrors; roomLabel: (room: RoomType) => string }) {
  const t = useTranslations('booking');
  const { packages, pricing, addons } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();
  const pkg = packages.find((p) => p.slug === booking.packageSlug);

  return (
    <>
      <h3 className="text-19 font-semibold">{t('packageHeading')}</h3>
      {pkg?.groupTour ? <p className="-mt-2 text-13 font-semibold text-blue-deep">{t('groupTourNote')}</p> : null}
      {pkg && !pkg.groupTour ? <GroupSizePicker pkg={pkg} /> : null}
      <div className="grid-auto-fit-240 grid gap-3.5">
        <Field variant="form" label={t('package')}>
          <select value={booking.packageSlug} onChange={(e) => booking.setPackage(e.target.value)} className={controlClass(false, 'form')}>
            {packages.map((p) => (
              <option key={p.slug} value={p.slug}>
                {p.title}
              </option>
            ))}
          </select>
        </Field>
        {pkg?.groupTour ? (
          <GroupTourDate pkg={pkg} error={errors.date} />
        ) : (
          <Field variant="form" label={t('date')} error={errors.date}>
            <input
              type="date"
              min={todayIso()}
              value={booking.date}
              onChange={(e) => booking.setDate(e.target.value)}
              className={controlClass(!!errors.date, 'form')}
              suppressHydrationWarning
            />
          </Field>
        )}
        <Field variant="form" label={t('travellers')}>
          <input
            type="number"
            min={1}
            max={pricing.maxTravellers}
            value={booking.pax}
            onChange={(e) => booking.setPax(Number(normalizeDigits(e.target.value)) || 1, pricing.maxTravellers)}
            className={controlClass(false, 'form')}
          />
        </Field>
        {pkg && pkg.hotelCategories.length > 0 ? (
          <Field variant="form" label={t('hotelCategory')}>
            <select
              value={booking.hotelCategory && pkg.hotelCategories.includes(booking.hotelCategory) ? booking.hotelCategory : (defaultHotelCategory(pkg.priceGrid) ?? '')}
              onChange={(e) => booking.setHotelCategory(e.target.value as HotelCategory)}
              className={controlClass(false, 'form')}
            >
              {pkg.hotelCategories.map((category) => (
                <option key={category} value={category}>
                  {t(`hotelCategories.${category}`)} · {f.bdt(basePrice(pkg, booking.pax, pricing.slabs, category))} {t('perPersonShort')}
                </option>
              ))}
            </select>
          </Field>
        ) : null}
        <Field variant="form" label={t('room')}>
          <select value={booking.room} onChange={(e) => booking.setRoom(e.target.value as RoomType)} className={controlClass(false, 'form')}>
            {ROOMS.map((room) => (
              <option key={room} value={room}>
                {roomLabel(room)}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <div className="grid-auto-fit-200 grid gap-2.5">
        {addons.map((addon) => {
          const on = booking.addons.includes(addon.code);
          return (
            <button
              key={addon.code}
              type="button"
              aria-pressed={on}
              onClick={() => booking.toggleAddon(addon.code)}
              className={`flex cursor-pointer flex-col gap-0.5 rounded-12 border-chip px-3.5 py-3 text-left text-ink-deep ${on ? 'border-blue bg-paper-alt' : 'border-hairline bg-white'}`}
            >
              <span className="text-14 font-semibold">{addon.name}</span>
              <span className="text-13 text-muted">
                {addon.unit === 'per_booking' ? t('addonPerBooking', { price: f.bdt(addon.price) }) : t('addonPerPerson', { price: f.bdt(addon.price) })}
              </span>
            </button>
          );
        })}
      </div>
    </>
  );
}
