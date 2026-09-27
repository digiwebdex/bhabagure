import type { PackageDepartureView, PackageView } from '@/lib/content/views';

/**
 * A fixed-departure group tour is booked on its scheduled departures only — there is no calendar
 * (docs/fixed-departure-group-tours.md). The API refuses any other date and a departure without seats.
 */
export function hasRoom(departure: PackageDepartureView, pax: number): boolean {
  return departure.seatsLeft >= pax;
}

/**
 * The date the booking travels on. A customized trip: the date picked. A group tour: the departure picked while it
 * still has room for everyone, else its only departure with room; '' when there is none to book.
 */
export function bookingDate(pkg: Pick<PackageView, 'groupTour' | 'departures'> | undefined, date: string, pax: number): string {
  if (!pkg?.groupTour) return date;
  const open = pkg.departures.filter((d) => hasRoom(d, pax));
  if (open.some((d) => d.departsOn === date)) return date;
  return open.length === 1 ? open[0].departsOn : '';
}
