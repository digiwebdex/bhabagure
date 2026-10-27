/**
 * Small rules for tour landing pages (docs/tour-landing-pages.md), kept apart so they are tested without a browser.
 */

/** Today in Dhaka, "2026-10-28": departures and brochures are Dhaka days. */
export function dhakaToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** The brochure's date: the one the API asked for (`?asOf=`), when it is a real date, else today in Dhaka. */
export function printDate(asOf: string | string[] | undefined, now: Date = new Date()): string {
  return typeof asOf === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(asOf) && !Number.isNaN(Date.parse(`${asOf}T00:00:00Z`)) ? asOf : dhakaToday(now);
}

/**
 * The date a "Book" link (`?book=1&date=…`, from the brochure) opens the booking form on: the link's date while it is
 * one of the tour's departures with seats left, else the page's own next departure, else none — the form then asks.
 */
export function bookingDateFromLink(departures: { departsOn: string; seatsLeft: number }[], linked: string | null, fallback: string | null): string | null {
  if (linked && departures.some((d) => d.departsOn === linked && d.seatsLeft > 0)) return linked;
  return fallback && departures.some((d) => d.departsOn === fallback) ? fallback : null;
}
