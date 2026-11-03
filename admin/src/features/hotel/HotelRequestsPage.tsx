import { useSearchParams } from 'react-router'

import type { HotelInquiry } from '../requests/api'
import { QuoteRequestsPage, type RequestSpec } from '../requests/QuoteRequestsPage'
import { ServiceRecordsPage, ServiceTabs } from '../services/ServiceRecordsPage'

const HOTEL: RequestSpec<HotelInquiry> = {
  kind: 'hotel',
  ns: 'hotel',
  cardTitle: 'Hotel quotation requests',
  testId: 'hotel-inquiries-table',
  summary: (row) => row.location ?? '?',
  details: (row, { date, number }, t) => {
    const nights = row.check_in && row.check_out ? Math.round((Date.parse(row.check_out) - Date.parse(row.check_in)) / 86_400_000) : null
    return [
      row.check_in && row.check_out ? `${date(row.check_in)} – ${date(row.check_out)}` : '—',
      nights ? t('hotel.nights', { count: nights, n: number(nights) }) : null,
      t('hotel.guests', { count: row.guests ?? 1, n: number(row.guests ?? 1) }),
      row.hotel_category ? t(`hotel.categories.${row.hotel_category}`) : null,
    ]
      .filter(Boolean)
      .join(' · ')
  },
  facts: (row, { date, number }, t) => [
    [t('hotel.location'), row.location ?? '—'],
    [t('hotel.checkIn'), row.check_in ? date(row.check_in) : '—'],
    [t('hotel.checkOut'), row.check_out ? date(row.check_out) : '—'],
    [t('hotel.categoryLabel'), row.hotel_category ? t(`hotel.categories.${row.hotel_category}`) : '—'],
    [t('hotel.guestsLabel'), number(row.guests ?? 1)],
    [t('hotel.note'), row.note ?? '—'],
  ],
  contact: (row, t) => ({
    subject: t('hotel.contactSubject', { location: row.location ?? '' }),
    message: t('hotel.contactMessage', { name: row.name, location: row.location ?? '', date: row.check_in ?? '' }),
  }),
}

/**
 * Hotel requests: the bookings invoices sold (docs/invoice-services.md), and the website's hotel quotation requests
 * (docs/phase-8-visa-quotes-pricing-downloads.md §4.B), where staff send the quotation as a reply (WhatsApp and email,
 * logged) and mark the request quoted.
 */
export function HotelRequestsPage() {
  const [params] = useSearchParams()
  return params.has('state') ? <QuoteRequestsPage spec={HOTEL} tabs={<ServiceTabs kind="hotel" active="enquiries" />} /> : <ServiceRecordsPage kind="hotel" />
}
