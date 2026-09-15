'use client';

import { useTranslations } from 'next-intl';

import { defaultHotelCategory, packagePerPerson, type HotelCategory, type RoomType } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { controlClass, Field } from '@/components/ui/Field';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeDigits, todayIso } from '@/lib/validators';
import { useBooking } from '@/state/booking';

import type { PackageStepErrors } from './validation';

const ROOMS: RoomType[] = ['twin', 'single', 'triple'];

export function PackageStep({ errors, roomLabel }: { errors: PackageStepErrors; roomLabel: (room: RoomType) => string }) {
  const t = useTranslations('booking');
  const { packages, pricing, addons } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();
  const pkg = packages.find((p) => p.slug === booking.packageSlug);

  return (
    <>
      <h3 className="text-19 font-semibold">{t('packageHeading')}</h3>
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
                  {t(`hotelCategories.${category}`)} · {f.bdt(packagePerPerson(pkg, booking.pax, pricing.slabs, category))} {t('perPersonShort')}
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
