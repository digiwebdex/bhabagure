'use client';

import { useLocale, useTranslations } from 'next-intl';

import { controlClass, Field } from '@/components/ui/Field';
import { isoToDayMonthYear, uploadPassportScan } from '@/lib/booking-api';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking, type ScannableField, type TravellerDraft } from '@/state/booking';

import { expiresTooSoon, type TravellerErrors } from './validation';

const MAX_SCAN_BYTES = 5 * 1024 * 1024;

/**
 * One card per traveller. A passport scan is read on the server (the machine-readable zone, with check digits); what
 * comes back only pre-fills the fields. A field whose check digit failed is marked "please confirm" until the
 * traveller edits it. If reading isn't available, the traveller types the details — the booking never waits on OCR.
 */
export function TravellersStep({ errors }: { errors: TravellerErrors[] }) {
  const t = useTranslations('booking');
  const f = useFormatters();
  const booking = useBooking();

  return (
    <>
      <div className="flex flex-wrap items-baseline justify-between gap-2.5">
        <h3 className="text-19 font-semibold">{t('travellersHeading')}</h3>
        <span className="text-13 text-muted">
          {t('travellersNote', {
            pax: booking.pax,
            paxText: f.number(booking.pax),
          })}
        </span>
      </div>
      {booking.travellers.map((traveller, i) => (
        <TravellerCard key={i} index={i} traveller={traveller} errors={errors[i] ?? {}} travelDate={booking.date} />
      ))}
      <p className="text-12 leading-1.6 text-muted">{t('scanConsent')}</p>
    </>
  );
}

function TravellerCard({ index, traveller, errors, travelDate }: { index: number; traveller: TravellerDraft; errors: TravellerErrors; travelDate: string }) {
  const t = useTranslations('booking');
  const locale = useLocale();
  const f = useFormatters();
  const update = useBooking((state) => state.updateTraveller);
  const lead = index === 0;
  // The lead needs a name and WhatsApp number; everything else can follow later.
  const status = lead ? (traveller.name.trim() && traveller.phone.trim() ? 'filled' : 'missing') : traveller.name.trim() ? 'filled' : 'later';
  const optional = (label: string) => t('optionalField', { label });
  const id = `traveller-${index}`;

  const set = (key: keyof TravellerDraft) => (value: string) => {
    const scannable = key === 'passport' || key === 'dob' || key === 'expiry';
    update(index, {
      [key]: value,
      ...(scannable ? { confirm: { ...traveller.confirm, [key]: false } } : {}),
    });
  };

  const onFile = async (file: File | undefined) => {
    if (!file) return;
    if (file.size > MAX_SCAN_BYTES) {
      update(index, { fileName: file.name, scan: 'error' });
      return;
    }
    update(index, { fileName: file.name, scan: 'uploading', scanToken: null });
    const result = await uploadPassportScan(file, locale);
    if (!result.ok) {
      update(index, {
        scan: result.reason === 'invalid' ? 'error' : 'unavailable',
      });
      return;
    }
    const { token, status, fields } = result.data;
    const current = useBooking.getState().travellers[index];
    if (!fields) {
      update(index, { scan: 'unavailable', scanToken: token });
      return;
    }
    const pick = (field: ScannableField, value: string | null, display: (v: string) => string, existing: string) =>
      value ? { [field]: display(value) } : { [field]: existing };
    update(index, {
      scan: status,
      scanToken: token,
      ocrFilled: true,
      name: fields.fullName || current.name,
      ...pick('passport', fields.passportNumber.value, (v) => v, current.passport),
      ...pick('dob', fields.dateOfBirth.value, isoToDayMonthYear, current.dob),
      ...pick('expiry', fields.passportExpiry.value, isoToDayMonthYear, current.expiry),
      confirm: {
        passport: !!fields.passportNumber.value && fields.passportNumber.confirm,
        dob: !!fields.dateOfBirth.value && fields.dateOfBirth.confirm,
        expiry: !!fields.passportExpiry.value && fields.passportExpiry.confirm,
      },
    });
  };

  const text = (key: keyof TravellerDraft, label: string, extra: Record<string, string> = {}) => {
    const confirm = (key === 'passport' || key === 'dob' || key === 'expiry') && traveller.confirm[key];
    return (
      <Field variant="form" label={label} error={errors[key]}>
        <input
          value={traveller[key] as string}
          onChange={(e) => set(key)(e.target.value)}
          aria-describedby={confirm ? `${id}-${key}-confirm` : undefined}
          className={`${controlClass(!!errors[key], 'form')} ${confirm ? 'border-orange-bright bg-orange-tint' : ''}`}
          {...extra}
        />
        {confirm ? (
          <span id={`${id}-${key}-confirm`} className="flex flex-wrap items-center gap-x-2 text-12 font-semibold text-amber">
            {t('scanConfirm')}
            <button
              type="button"
              onClick={() =>
                update(index, {
                  confirm: { ...traveller.confirm, [key]: false },
                })
              }
              className="cursor-pointer text-blue underline"
            >
              {t('scanConfirmed')}
            </button>
          </span>
        ) : null}
      </Field>
    );
  };

  return (
    <section aria-labelledby={id} className="flex flex-col gap-3.5 rounded-16 border border-hairline p-4">
      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <span id={id} className="text-15 font-semibold">
          {t('traveller', { n: f.number(index + 1) })}
        </span>
        <span
          className={`rounded-pill px-2.5 py-0.75 text-12 font-semibold whitespace-nowrap ${
            status === 'filled' ? 'bg-green-tint text-green' : status === 'missing' ? 'bg-orange-tint text-amber' : 'bg-row-alt text-muted'
          }`}
        >
          {status === 'filled' ? t('statusFilled') : status === 'missing' ? t('statusMissing') : t('statusLater')}
        </span>
      </div>

      <label className="flex min-w-55 flex-1 cursor-pointer items-center gap-2.5 rounded-12 border-chip border-dashed border-input bg-row-alt px-4 py-3 hover:border-blue">
        <span aria-hidden className="flex size-8.5 shrink-0 items-center justify-center rounded-9 bg-linear-135/srgb from-blue to-blue-deep text-15 text-white">
          ⎙
        </span>
        <span className="flex min-w-0 flex-col">
          <span className="text-14 font-semibold">{t('upload')}</span>
          <span className="truncate text-12 text-muted">{traveller.fileName || t('uploadHint')}</span>
        </span>
        <input
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          disabled={traveller.scan === 'uploading'}
          onChange={(e) => void onFile(e.target.files?.[0])}
          className="sr-only"
        />
      </label>
      {traveller.scan !== 'idle' ? (
        <p role="status" className={`text-12.5 ${traveller.scan === 'read' ? 'text-green' : traveller.scan === 'uploading' ? 'text-blue' : 'text-amber'}`}>
          {t(`scan.${traveller.scan}`)}
        </p>
      ) : null}

      <div className="grid-auto-fit-200 grid gap-3">
        {text('name', lead ? t('name') : optional(t('name')), { autoComplete: 'name' })}
        {text('phone', lead ? t('whatsapp') : optional(t('phone')), {
          placeholder: '01XXXXXXXXX',
          inputMode: 'tel',
          autoComplete: 'tel',
        })}
        {text('passport', optional(t('passport')), {
          autoComplete: 'off',
          autoCapitalize: 'characters',
        })}
        {text('dob', optional(t('dob')), {
          placeholder: t('datePh'),
          inputMode: 'numeric',
        })}
        {text('expiry', optional(t('expiry')), {
          placeholder: t('datePh'),
          inputMode: 'numeric',
        })}
        {text('email', optional(t('email')), {
          placeholder: 'name@email.com',
          type: 'email',
          autoComplete: 'email',
        })}
      </div>
      {expiresTooSoon(traveller.expiry, travelDate) ? (
        <div role="alert" className="rounded-10 bg-orange-tint px-3 py-2.5 text-13 text-amber">
          {t('expiryWarning')}
        </div>
      ) : null}
    </section>
  );
}
