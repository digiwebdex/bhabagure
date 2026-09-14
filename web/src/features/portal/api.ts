'use client';

import { useLocale } from 'next-intl';
import { useCallback, useEffect, useState } from 'react';

import { portalCall, type PortalFailure } from '@/lib/customer-api';
import type { PublicBooking } from '@/lib/booking-api';

/** Shapes from api/app/Http/Controllers/Api/V1/Portal/* (docs/phase-6-customer-portal.md §3). camelCase like the public API. */

export type BookingStatus = 'inquiry' | 'confirmed' | 'completed' | 'cancelled';

export type ReadinessCheck = { key: 'paid' | 'passports' | 'documents' | 'visa' | 'insurance' | 'etickets'; done: boolean; waitingOn: string[] };
export type Readiness = { checks: ReadinessCheck[]; done: number; total: number };

export type TripSummary = {
  reference: string;
  title: string;
  status: BookingStatus;
  paymentStatus: 'unpaid' | 'partial' | 'paid';
  travelStart: string | null;
  travelEnd: string | null;
  pax: number;
  total: number;
  paid: number;
  due: number;
  upcoming: boolean;
  invoice: { number: string; url: string; pdfUrl: string } | null;
};

export type TripsData = { next: (TripSummary & { readiness: Readiness; daysToGo: number | null }) | null; trips: TripSummary[] };

export type ETicket = { id: number; travellerId: number; traveller: string; airline: string; pnr: string; ticketNumber: string; route: string | null; departsOn: string | null; hasFile: boolean; issuedAt: string };

export type TripDetail = PublicBooking & {
  tickets: ETicket[];
  upcoming: boolean;
  daysToGo: number | null;
  readiness: Readiness;
  itinerary: { day: number; title: string; body: string }[];
};

export type QuotationStatus = 'sent' | 'expired' | 'accepted' | 'declined' | 'converted';
export type QuotationSummary = {
  number: string;
  title: string;
  status: QuotationStatus;
  travelDate: string | null;
  pax: number;
  total: number;
  validUntil: string;
  sentAt: string | null;
  viewedAt: string | null;
  acceptedAt: string | null;
  canAccept: boolean;
  url: string;
  pdfUrl: string;
};
export type QuotationDetail = QuotationSummary & {
  lines: { kind: string; title: string; quantity: number; unitPrice: number; amount: number }[];
  discount: number;
  serviceCharge: number;
  room: string;
  durationDays: number | null;
  durationNights: number | null;
  includesAirfare: boolean | null;
};

export type DocumentKind = 'passport_scan' | 'photo' | 'visa' | 'insurance';
export type DocumentStatus = 'missing' | 'uploaded' | 'verified' | 'rejected' | 'pending' | 'issued' | 'not_required';
export type DocumentSlot = { kind: DocumentKind; status: DocumentStatus; note: string | null; uploadedAt: string | null; reviewedAt: string | null; hasFile: boolean };
export type DocumentsData = {
  trips: {
    reference: string;
    title: string;
    travelStart: string | null;
    travellers: { id: number; name: string; isLead: boolean; passportOnFile: boolean; documents: DocumentSlot[] }[];
  }[];
  toDo: number;
};

export type PaymentsData = {
  paid: number;
  due: number;
  bookings: number;
  openBookings: number;
  history: {
    id: number;
    at: string;
    reference: string;
    title: string;
    kind: 'payment' | 'charge' | 'reversal';
    method: string;
    externalRef: string | null;
    amount: number;
    reversed: boolean;
  }[];
};

export type TicketStatus = 'open' | 'answered' | 'closed';
export type TicketMessage = { id: number; author: 'customer' | 'staff'; staffName: string | null; body: string; at: string };
export type TicketSummary = { number: string; subject: string; status: TicketStatus; bookingReference: string | null; createdAt: string; lastMessage: TicketMessage | null };
export type TicketDetail = TicketSummary & { messages: TicketMessage[] };
export type SupportData = { tickets: TicketSummary[]; bookings: { reference: string; title: string }[] };

export type Loadable<T> =
  | { state: 'loading' }
  | { state: 'ready'; data: T }
  | { state: 'not_found' }
  | { state: 'error' };

/** Loads one portal endpoint for the signed-in customer, again when the language changes or `reload` is called. */
export function usePortal<T>(path: string | null): [Loadable<T>, () => void, (data: T) => void] {
  const locale = useLocale();
  const [view, setView] = useState<Loadable<T>>({ state: 'loading' });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (path === null) return;
    let live = true;
    void portalCall<T>(path, locale).then((result) => {
      if (!live) return;
      if (result.ok) setView({ state: 'ready', data: result.data });
      else setView({ state: result.reason === 'not_found' ? 'not_found' : 'error' });
    });
    return () => {
      live = false;
    };
  }, [path, locale, attempt]);

  const reload = useCallback(() => setAttempt((n) => n + 1), []);
  const replace = useCallback((data: T) => setView({ state: 'ready', data }), []);
  return [view, reload, replace];
}

export const acceptQuotation = (number: string, locale: string) =>
  portalCall<QuotationSummary>(`portal/quotations/${encodeURIComponent(number)}/accept`, locale, { method: 'POST' });

export function uploadDocument(travellerId: number, kind: DocumentKind, file: File, locale: string) {
  const body = new FormData();
  body.append('file', file);
  return portalCall<DocumentSlot[]>(`portal/travellers/${travellerId}/documents/${kind}`, locale, { method: 'POST', body });
}

export const addPassportNumber = (travellerId: number, passportNumber: string, passportExpiry: string, locale: string) =>
  portalCall<{ passportOnFile: boolean }>(`portal/travellers/${travellerId}/passport`, locale, {
    method: 'PUT',
    body: JSON.stringify({ passport_number: passportNumber, passport_expiry: passportExpiry }),
  });

export const openTicket = (input: { bookingReference: string | null; subject: string; body: string }, locale: string) =>
  portalCall<TicketDetail>('portal/support', locale, {
    method: 'POST',
    body: JSON.stringify({ booking_reference: input.bookingReference, subject: input.subject, body: input.body }),
  });

export const sendTicketMessage = (number: string, body: string, locale: string) =>
  portalCall<TicketDetail>(`portal/support/${encodeURIComponent(number)}/messages`, locale, { method: 'POST', body: JSON.stringify({ body }) });

export type { PortalFailure };
