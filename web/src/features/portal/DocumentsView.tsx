'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useRef, useState, type FormEvent } from 'react';

import { Field, controlClass } from '@/components/ui/Field';
import { buttonClass } from '@/components/ui/button';
import { openPortalFile } from '@/lib/customer-api';
import { useFormatters } from '@/lib/use-formatters';
import { isPassportNumber, normalizeDigits, parseDayMonthYear } from '@/lib/validators';

import { addPassportNumber, uploadDocument, usePortal, type DocumentKind, type DocumentSlot, type DocumentsData } from './api';
import { Card, documentTone, Heading, LoadState, Pill } from './ui';

/** Matches TravellerDocuments::MAX_KB and ::MIMES in the API. */
const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPT: Record<'passport_scan' | 'photo', string> = { passport_scan: 'image/jpeg,image/png,image/webp,application/pdf', photo: 'image/jpeg,image/png,image/webp' };

/**
 * Documents (§3.3): per traveller on each upcoming trip, the passport scan and photo to upload, a passport number to add
 * while none is on file, and the visa and insurance statuses staff set. Passport digits are never shown back.
 */
export function DocumentsView() {
  const t = useTranslations('portal.documents');
  const f = useFormatters();
  const [view, reload] = usePortal<DocumentsData>('portal/documents');

  if (view.state !== 'ready') return <LoadState state={view.state} onRetry={reload} />;
  const { trips, toDo } = view.data;

  return (
    <Card>
      <Heading
        as="h1"
        title={t('title')}
        aside={<span className={`text-13 font-semibold ${toDo > 0 ? 'text-amber' : 'text-green'}`}>{toDo > 0 ? t('toDo', { count: toDo, countText: f.number(toDo) }) : t('allDone')}</span>}
      />
      {trips.length === 0 ? <p className="m-0 text-14 text-app-muted">{t('noTrips')}</p> : null}
      {trips.map((trip) =>
        trip.travellers.map((traveller) => (
          <div key={traveller.id} className="flex flex-col gap-3 rounded-14 border border-portal-line p-3.75">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <strong className="text-15 font-semibold">{traveller.name}</strong>
              <span className="text-12 text-app-muted">
                {trip.reference} · {trip.title}
              </span>
            </div>
            <div className="grid-auto-fit-half-150 grid gap-2.25">
              {traveller.documents.map((slot) =>
                slot.kind === 'passport_scan' || slot.kind === 'photo' ? (
                  <UploadSlot key={slot.kind} travellerId={traveller.id} slot={slot} onChanged={reload} />
                ) : (
                  <StatusSlot key={slot.kind} slot={slot} />
                ),
              )}
            </div>
            {traveller.passportOnFile ? null : <PassportNumberForm travellerId={traveller.id} travelStart={trip.travelStart} onSaved={reload} />}
          </div>
        )),
      )}
    </Card>
  );
}

function UploadSlot({ travellerId, slot, onChanged }: { travellerId: number; slot: DocumentSlot; onChanged: () => void }) {
  const t = useTranslations('portal.documents');
  const locale = useLocale();
  const f = useFormatters();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const kind = slot.kind as 'passport_scan' | 'photo';
  const canUpload = slot.status !== 'verified';
  const done = slot.status === 'verified' || slot.status === 'uploaded';

  const choose = async (file: File | undefined) => {
    if (!file) return;
    setError(null);
    if (file.size > MAX_BYTES) return setError(t('tooLarge'));
    setBusy(true);
    const result = await uploadDocument(travellerId, kind, file, locale);
    setBusy(false);
    if (input.current) input.current.value = '';
    if (result.ok) onChanged();
    else setError(result.reason === 'invalid' ? t('wrongType') : (result.message ?? t('uploadFailed')));
  };

  const note =
    slot.status === 'missing'
      ? t('tapToUpload')
      : slot.status === 'rejected'
        ? t('rejected', { reason: slot.note ?? '' })
        : slot.status === 'uploaded'
          ? t('waitingReview', { date: slot.uploadedAt ? f.date(slot.uploadedAt.slice(0, 10)) : '' })
          : t('verified');

  return (
    <div
      className={`flex flex-col gap-2 rounded-11 border-chip p-2.75 ${
        done ? 'border-green-line bg-green-wash' : slot.status === 'rejected' ? 'border-red-line bg-red-tint/40' : 'border-dashed border-portal-input bg-app-surface'
      }`}
    >
      <label className={`flex items-center gap-2.25 ${canUpload ? 'cursor-pointer' : ''}`}>
        <span aria-hidden className={`flex size-6.5 shrink-0 items-center justify-center rounded-8 text-12 text-white ${done ? 'bg-green' : slot.status === 'rejected' ? 'bg-red' : 'bg-blue'}`}>
          {done ? '✓' : '⎙'}
        </span>
        <span className="flex min-w-0 flex-col leading-1.25">
          <span className="text-13 font-semibold">{t(`kinds.${kind}`)}</span>
          <span className="text-11 text-app-muted">{busy ? t('uploading') : note}</span>
        </span>
        {canUpload ? (
          <input ref={input} type="file" accept={ACCEPT[kind]} className="sr-only" disabled={busy} onChange={(e) => void choose(e.target.files?.[0])} aria-label={t('uploadLabel', { kind: t(`kinds.${kind}`) })} />
        ) : null}
      </label>
      {slot.hasFile ? (
        <button type="button" onClick={() => void openPortalFile(`portal/travellers/${travellerId}/documents/${kind}/file`, locale)} className="cursor-pointer self-start text-12 font-semibold text-blue hover:text-orange">
          {t('viewUpload')}
        </button>
      ) : null}
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
    </div>
  );
}

function StatusSlot({ slot }: { slot: DocumentSlot }) {
  const t = useTranslations('portal.documents');
  return (
    <div className="flex items-center gap-2.25 rounded-11 border-chip border-portal-line bg-app-surface p-2.75">
      <span className="flex min-w-0 flex-1 flex-col gap-1 leading-1.25">
        <span className="text-13 font-semibold">{t(`kinds.${slot.kind as DocumentKind}`)}</span>
        {slot.note ? <span className="text-11 text-app-muted">{slot.note}</span> : null}
      </span>
      <Pill tone={documentTone(slot.status)}>{t(`status.${slot.status}`)}</Pill>
    </div>
  );
}

function PassportNumberForm({ travellerId, travelStart, onSaved }: { travellerId: number; travelStart: string | null; onSaved: () => void }) {
  const t = useTranslations('portal.documents');
  const tv = useTranslations('validation');
  const locale = useLocale();
  const [number, setNumber] = useState('');
  const [expiry, setExpiry] = useState('');
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const cleanNumber = normalizeDigits(number).replace(/\s+/g, '').toUpperCase();
  const expiryIso = parseDayMonthYear(expiry);
  const errors = {
    number: isPassportNumber(cleanNumber) ? undefined : tv('passport'),
    expiry: !expiryIso ? tv('date') : travelStart && expiryIso <= travelStart ? t('expiryAfterTravel') : undefined,
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setAttempted(true);
    if (errors.number || errors.expiry || busy || !expiryIso) return;
    setBusy(true);
    setError(null);
    const result = await addPassportNumber(travellerId, cleanNumber, expiryIso, locale);
    setBusy(false);
    if (result.ok) onSaved();
    else setError(result.message ?? t('saveFailed'));
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-2.5 rounded-12 bg-orange-tint p-3">
      <span className="text-13 font-semibold text-orange-ink">{t('passportMissing')}</span>
      <div className="grid-auto-fit-200 grid gap-2.5">
        <Field label={t('passportNumber')} error={attempted ? errors.number : undefined}>
          <input value={number} onChange={(e) => setNumber(e.target.value)} autoComplete="off" placeholder="BW0912345" className={controlClass(attempted && !!errors.number)} />
        </Field>
        <Field label={t('passportExpiry')} error={attempted ? errors.expiry : undefined}>
          <input value={expiry} onChange={(e) => setExpiry(e.target.value)} inputMode="numeric" placeholder="DD/MM/YYYY" className={controlClass(attempted && !!errors.expiry)} />
        </Field>
      </div>
      <span className="text-12 text-app-muted">{t('passportPrivacy')}</span>
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
      <button type="submit" disabled={busy} className={buttonClass('primary', 'sm', 'self-start')}>
        {t('savePassport')}
      </button>
    </form>
  );
}
