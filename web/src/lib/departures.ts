/**
 * Which departure of a fixed-departure group tour the website shows (client, 2026-10-01; docs/departure-prices.md): the
 * one staff featured ("Show on the card") while it still has seats, else the next one that has. Its date and its price
 * are what the card, the package page and the booking form start with. `departures` come soonest first.
 */
export function shownDeparture<D extends { seatsLeft: number; featured: boolean }>(departures: readonly D[], seats = 1): D | undefined {
  return departures.find((d) => d.featured && d.seatsLeft >= seats) ?? departures.find((d) => d.seatsLeft >= seats);
}
