import type { AirInquiry } from '../requests/api'
import { QuoteRequestsPage, type RequestSpec } from '../requests/QuoteRequestsPage'

const route = (row: AirInquiry) => `${row.from ?? '?'} → ${row.to ?? '?'}`

const AIR: RequestSpec<AirInquiry> = {
  kind: 'air',
  ns: 'air',
  cardTitle: { bn: 'টিকেট ইনকোয়্যারি', en: 'Ticket enquiries' },
  testId: 'air-inquiries-table',
  summary: route,
  details: (row, { date, number }, t) =>
    `${row.depart_on ? date(row.depart_on) : '—'}${row.return_on ? ` – ${date(row.return_on)}` : ` · ${t('air.oneWay')}`} · ${t('air.passengers', { count: row.passengers ?? 1, n: number(row.passengers ?? 1) })} · ${t(`air.cabin.${row.cabin_class ?? 'economy'}`)}`,
  facts: (row, { date, number }, t) => [
    [t('air.route'), route(row)],
    [t('air.departOn'), row.depart_on ? date(row.depart_on) : '—'],
    [t('air.returnOn'), row.return_on ? date(row.return_on) : t('air.oneWay')],
    [t('air.passengersLabel'), number(row.passengers ?? 1)],
    [t('air.cabinLabel'), t(`air.cabin.${row.cabin_class ?? 'economy'}`)],
  ],
  contact: (row, t) => ({
    subject: t('air.contactSubject', { route: route(row) }),
    message: t('air.contactMessage', { name: row.name, route: route(row), date: row.depart_on ?? '' }),
  }),
}

/**
 * Air ticketing — the enquiry queue (docs/phase-5-admin-core.md §4.7). Staff reply from here (WhatsApp and email, logged)
 * or from their own WhatsApp or email, and mark the enquiry quoted; fares, PNRs and airline commission come with the rest
 * of air ticketing.
 */
export function AirTicketingPage() {
  return <QuoteRequestsPage spec={AIR} />
}
