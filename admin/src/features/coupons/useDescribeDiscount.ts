import { useTranslation } from 'react-i18next'

import { useFormat } from '../../lib/useFormat'
import type { Coupon } from './api'

/** A coupon's discount in a few words — "10% off · up to BDT 2,000", "BDT 500 off" — and its minimum, if any. */
export function useDescribeDiscount() {
  const { t } = useTranslation()
  const { bdt, percent } = useFormat()
  return (coupon: Pick<Coupon, 'discount_type' | 'discount_value' | 'max_discount_amount' | 'min_booking_amount'>): [string, string | null] => {
    const off = coupon.discount_type === 'percent' ? t('coupons.offPercent', { value: percent(coupon.discount_value) }) : t('coupons.offAmount', { amount: bdt(coupon.discount_value) })
    return [
      coupon.discount_type === 'percent' && coupon.max_discount_amount ? `${off} · ${t('coupons.upTo', { amount: bdt(coupon.max_discount_amount) })}` : off,
      coupon.min_booking_amount ? t('coupons.minimum', { amount: bdt(coupon.min_booking_amount) }) : null,
    ]
  }
}
