import { packagePerPerson, quoteBooking, type HotelCategory, type PricingConfig, type RoomType } from '@bhabaghure/pricing';

import type { PackageView } from '@/lib/content/views';

type Priced = Pick<PackageView, 'listPrice' | 'priceGrid' | 'groupTour' | 'roomRates'>;

/**
 * A package's base price per person — triple sharing (docs/room-rates.md): the size table's or the group rate for `pax`,
 * or a group tour's fixed price. What cards, lists and the size chips show.
 */
export function basePrice(pkg: Priced, pax: number, slabs: PricingConfig['slabs'], category?: HotelCategory | null): number {
  return packagePerPerson({ listPrice: pkg.listPrice, priceGrid: pkg.priceGrid, fixedPrice: pkg.groupTour }, pax, slabs, category);
}

/**
 * What one traveller pays in each room for `pax` travellers, supplement included — priced by quoteBooking exactly as
 * the booking will be (a size table's 1-person price already includes the single room).
 */
export function roomPricesFor(pkg: Priced, pax: number, config: PricingConfig, category: HotelCategory | null): Record<RoomType, number> {
  const price = (room: RoomType) => {
    const quote = quoteBooking({
      listPrice: pkg.listPrice,
      pax,
      room,
      addons: [],
      config,
      grid: pkg.priceGrid,
      hotelCategory: category,
      rooms: pkg.roomRates,
      fixedPrice: pkg.groupTour,
    });
    return Math.round((quote.subtotal + quote.singleSupplement) / pax);
  };
  return { triple: price('triple'), twin: price('twin'), single: price('single') };
}

/** The percentage a room adds, for its label: the package's own, else the site-wide single supplement (twin then adds nothing). */
export function roomPercent(pkg: Pick<PackageView, 'roomRates'>, room: 'twin' | 'single', config: Pick<PricingConfig, 'singleRoomSupplementPercent'>): number {
  if (pkg.roomRates) return room === 'single' ? pkg.roomRates.singleSupplementPercent : pkg.roomRates.twinSupplementPercent;
  return room === 'single' ? config.singleRoomSupplementPercent : 0;
}
