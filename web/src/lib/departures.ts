/**
 * Which departure of a fixed-departure group tour the website shows (client, 2026-10-01; docs/departure-prices.md): the
 * one staff featured ("Show on the card") while it still has seats, else the next one that has. Its date and its price
 * are what the card, the package page and the booking form start with. `departures` come soonest first.
 */
export function shownDeparture<D extends { seatsLeft: number; featured: boolean }>(departures: readonly D[], seats = 1): D | undefined {
  return departures.find((d) => d.featured && d.seatsLeft >= seats) ?? departures.find((d) => d.seatsLeft >= seats);
}

/**
 * The departure a package page or modal shows (client, 2026-10-01): the date the visitor picked there while it has
 * seats, else the one the card shows. Its dates and price are the page's, and Book starts on it.
 */
export function pickedDeparture<D extends { departsOn: string; seatsLeft: number; featured: boolean }>(departures: readonly D[], picked: string | null): D | undefined {
  return departures.find((d) => d.departsOn === picked && d.seatsLeft > 0) ?? shownDeparture(departures);
}
