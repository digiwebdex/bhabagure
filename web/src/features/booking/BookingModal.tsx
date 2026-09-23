'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useMemo } from 'react';

import { defaultHotelCategory, quoteBooking, type RoomType } from '@bhabaghure/pricing';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Modal, ModalClose } from '@/components/ui/Modal';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking, type BookingStep } from '@/state/booking';

import { couponDiscountFor, useCouponSync } from './coupon';
import { PackageStep } from './PackageStep';
import { PaymentStep } from './PaymentStep';
import { ReviewStep } from './ReviewStep';
import { StepIndicator } from './StepIndicator';
import { TravellersStep } from './TravellersStep';
import { validatePackageStep, validateTravellers } from './validation';

/**
 * Online booking in four steps: package and travellers · traveller details (passport scan) · review · payment
 * (SSLCommerz). Every step prices with @bhabaghure/pricing — the same function as the cards and the detail modal —
 * and the API checks the total with its PHP twin before anything is charged.
 */
export function BookingModal() {
  const t = useTranslations('booking');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const locale = useLocale() as 'bn' | 'en';
  const { packages, pricing, addons } = useSiteContent();
  const f = useFormatters();
  const booking = useBooking();

  const pkg = packages.find((p) => p.slug === booking.packageSlug) ?? packages[0];
  const selectedAddons = addons.filter((a) => booking.addons.includes(a.code));
  // A grid package is quoted in the chosen hotel category, or its default until one is chosen.
  const hotelCategory = pkg && pkg.hotelCategories.length > 0 ? (booking.hotelCategory && pkg.hotelCategories.includes(booking.hotelCategory) ? booking.hotelCategory : defaultHotelCategory(pkg.priceGrid)) : null;
  // A coupon's discount as the API worked it out, while its answer still describes this booking (docs/coupons.md).
  const couponOff = couponDiscountFor(booking, hotelCategory);
  useCouponSync(hotelCategory, locale, { unavailable: t('coupon.unavailable'), rateLimited: t('coupon.rateLimited') });
  const quote = useMemo(
    () =>
      pkg
        ? quoteBooking({
            listPrice: pkg.listPrice,
            pax: booking.pax,
            room: booking.room,
            addons: selectedAddons,
            config: pricing,
            grid: pkg.priceGrid,
            hotelCategory,
            discount: couponOff,
          })
        : null,
    [pkg, booking.pax, booking.room, selectedAddons, pricing, hotelCategory, couponOff],
  );

  if (!pkg || !quote) return null;

  const stepErrors = booking.step === 1 ? validatePackageStep(booking, tv) : booking.step === 2 ? validateTravellers(booking, tv) : null;
  const stepInvalid = stepErrors ? stepErrors.invalid : booking.step === 3 ? !booking.terms : false;

  const next = () => {
    booking.markAttempted(booking.step);
    if (stepInvalid) return;
    booking.goNext();
  };

  const roomLabel = (room: RoomType) =>
    room === 'single'
      ? t('roomSingle', {
          percent: f.percent(pricing.singleRoomSupplementPercent),
        })
      : room === 'triple'
        ? t('roomTriple')
        : t('roomTwin');

  return (
    <Modal open={booking.open} onClose={booking.close} labelledBy="booking-title" size="lg" layer="booking">
      <div className="flex shrink-0 items-start justify-between gap-3.5 border-b border-hairline px-fluid-18-28 py-4.5">
        <div className="min-w-0">
          <h2 id="booking-title" className="text-fluid-20-26 font-bold tracking-title">
            {t('title')}
          </h2>
          <p className="mt-0.75 font-display text-13 text-muted">{t('subtitle')}</p>
        </div>
        <ModalClose onClick={booking.close} label={tc('close')} />
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-fluid-18-28 pt-booking-body-top pb-fluid-20-28">
        <StepIndicator current={booking.step} />

        {booking.step === 1 ? <PackageStep errors={booking.attempted[1] ? validatePackageStep(booking, tv).errors : {}} roomLabel={roomLabel} /> : null}
        {booking.step === 2 ? <TravellersStep errors={booking.attempted[2] ? validateTravellers(booking, tv).errors : []} /> : null}
        {booking.step === 3 ? (
          <ReviewStep pkg={pkg} quote={quote} hotelCategory={hotelCategory} termsError={booking.attempted[3] && !booking.terms ? t('termsRequired') : undefined} />
        ) : null}
        {booking.step === 4 ? <PaymentStep pkg={pkg} quote={quote} hotelCategory={hotelCategory} /> : null}

        {booking.attempted[booking.step as BookingStep] && stepInvalid && booking.step < 3 ? (
          <p role="alert" className="rounded-10 bg-orange-tint px-3 py-2.5 text-13 font-semibold text-amber">
            {t('fixErrors')}
          </p>
        ) : null}

        <div className="flex flex-wrap items-center justify-between gap-2.5 border-t border-hairline pt-4">
          <button
            type="button"
            onClick={booking.goBack}
            disabled={booking.step === 1}
            className={buttonClass('outlineInk', 'none', 'px-5.5 py-3 text-14 disabled:text-on-navy disabled:opacity-100')}
          >
            {t('back')}
          </button>
          {booking.step < 4 ? (
            <button
              type="button"
              onClick={next}
              className={buttonClass('cta', 'none', `px-6.5 py-3.25 text-15 shadow-glow-orange-soft ${stepInvalid ? 'cursor-not-allowed opacity-50' : ''}`)}
            >
              {t('next')}
            </button>
          ) : null}
        </div>
      </div>
    </Modal>
  );
}
