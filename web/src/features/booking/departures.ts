import type { PackageDepartureView, PackageView } from '@/lib/content/views';
import { shownDeparture } from '@/lib/departures';

/**
 * A fixed-departure group tour is booked on its scheduled departures only — there is no calendar
 * (docs/fixed-departure-group-tours.md). The API refuses any other date and a departure without seats.
 */
export function hasRoom(departure: PackageDepartureView, pax: number): boolean {
  return departure.seatsLeft >= pax;
}

/**
 * The date the booking travels on. A customized trip: the date picked. A group tour: the departure picked while it
 * still has room for everyone, else the one its card shows — the featured departure while it has room, else the next
 * with room (docs/departure-prices.md); '' when there is none to book.
 */
export function bookingDate(pkg: Pick<PackageView, 'groupTour' | 'departures'> | undefined, date: string, pax: number): string {
  if (!pkg?.groupTour) return date;
  const open = pkg.departures.filter((d) => hasRoom(d, pax));
  if (open.some((d) => d.departsOn === date)) return date;
  return shownDeparture(pkg.departures, pax)?.departsOn ?? '';
}

/**
 * The package as it is priced on `date` (docs/departure-prices.md): a group tour takes that departure's own price, and
 * keeps the one its card shows while no date is chosen. Everything that prices a booking reads `listPrice` from this.
 */
export function pricedOn<P extends Pick<PackageView, 'groupTour' | 'departures' | 'listPrice'>>(pkg: P, date: string): P {
  if (!pkg.groupTour) return pkg;
  const departure = pkg.departures.find((d) => d.departsOn === date);
  return departure ? { ...pkg, listPrice: departure.price } : pkg;
}
