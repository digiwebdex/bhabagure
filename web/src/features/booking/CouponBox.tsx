'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { controlClass } from '@/components/ui/Field';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking } from '@/state/booking';

import { applyCoupon, removeCoupon } from './coupon';

/**
 * The coupon row under the price breakdown (docs/coupons.md §2.7): a code and Apply; applied, the saving and Remove;
 * refused, the API's reason. The discount is always the API's — this box never works one out.
 */
export function CouponBox({ hotelCategory }: { hotelCategory: string | null }) {
  const t = useTranslations('booking.coupon');
  const locale = useLocale() as 'bn' | 'en';
  const f = useFormatters();
  const booking = useBooking();
  const [code, setCode] = useState('');
  const [empty, setEmpty] = useState(false);
  const checking = booking.couponCheck.status === 'checking';
  const messages = { unavailable: t('unavailable'), rateLimited: t('rateLimited') };

  if (booking.coupon) {
    return (
      <div role="status" data-testid="coupon-applied" className="flex flex-wrap items-center justify-between gap-2.5 rounded-12 border border-green-panel-line bg-green-panel px-3.5 py-2.75">
        <span className="flex min-w-0 items-center gap-2 text-13.5 text-green-deep">
          <span aria-hidden>✓</span>
          <span className="min-w-0">
            <strong className="font-display tracking-chip">{booking.coupon.code}</strong> ·{' '}
            {checking
              ? t('rechecking')
              : t.rich('applied', { saving: f.bdt(booking.coupon.discount), amount: (chunks) => <span className="whitespace-nowrap">{chunks}</span> })}
          </span>
        </span>
        <button type="button" onClick={removeCoupon} className="cursor-pointer text-13 font-semibold text-blue underline">
          {t('remove')}
        </button>
      </div>
    );
  }

  const apply = () => {
    if (!code.trim()) {
      setEmpty(true);
      return;
    }
    setEmpty(false);
    void applyCoupon(code, hotelCategory, locale, messages);
  };
  const refused = booking.couponCheck.status === 'refused' ? booking.couponCheck.message : null;

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor="coupon-code" className="text-13 font-semibold text-ink-deep">
        {t('label')}
      </label>
      <div className="flex gap-2">
        <input
          id="coupon-code"
          value={code}
          onChange={(event) => setCode(event.target.value.toUpperCase())}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              apply();
            }
          }}
          placeholder={t('placeholder')}
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={40}
          aria-invalid={!!refused || empty}
          aria-describedby={refused || empty ? 'coupon-note' : undefined}
          className={`${controlClass(!!refused || empty, 'form')} min-w-0 flex-1 font-display tracking-chip`}
        />
        <button type="button" onClick={apply} disabled={checking} aria-disabled={checking} className={buttonClass('outlineInk', 'none', 'shrink-0 px-4.5 py-2.5 text-14')}>
          {checking ? t('checking') : t('apply')}
        </button>
      </div>
      {refused || empty ? (
        <p id="coupon-note" role="alert" className="text-12.5 font-semibold text-amber">
          {empty ? t('empty') : refused}
        </p>
      ) : null}
    </div>
  );
}
