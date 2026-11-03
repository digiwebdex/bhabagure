import { useSearchParams } from 'react-router'

import type { AirInquiry } from '../requests/api'
import { QuoteRequestsPage, type RequestSpec } from '../requests/QuoteRequestsPage'
import { ServiceRecordsPage, ServiceTabs } from '../services/ServiceRecordsPage'

const route = (row: AirInquiry) => `${row.from ?? '?'} → ${row.to ?? '?'}`

const AIR: RequestSpec<AirInquiry> = {
  kind: 'air',
  ns: 'air',
  cardTitle: 'Ticket enquiries',
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
 * Air ticketing: the tickets invoices sold (docs/invoice-services.md), and the enquiry queue (docs/phase-5-admin-core.md
 * §4.7), where staff reply (WhatsApp and email, logged) or write from their own WhatsApp or email, and mark the enquiry
 * quoted. The queue's filters are in the address (the sidebar badge opens ?state=open&stale=1), which is what opens it.
 */
export function AirTicketingPage() {
  const [params] = useSearchParams()
  return params.has('state') ? <QuoteRequestsPage spec={AIR} tabs={<ServiceTabs kind="air" active="enquiries" />} /> : <ServiceRecordsPage kind="air" />
}
