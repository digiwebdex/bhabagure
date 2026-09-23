'use client';

import { useTranslations } from 'next-intl';

import { controlClass, Field } from '@/components/ui/Field';
import { useFormatters } from '@/lib/use-formatters';

/** Matches LoginCodes::MINUTES in the API. */
const CODE_MINUTES = 10;

export type CodeNote = { tone: 'error' | 'info'; text: string } | null;

/**
 * The code sent to the lead traveller's mobile before the booking is saved (docs/booking-phone-verification.md): where it
 * went, the box for it, a new code once the wait is over, and a way back to change the number.
 */
export function PhoneCodeBox({
  phone,
  code,
  onCode,
  note,
  waitSeconds,
  sending,
  onResend,
  onChangeNumber,
}: {
  /** 8801XXXXXXXXX: the number the code went to. */
  phone: string;
  code: string;
  onCode: (code: string) => void;
  note: CodeNote;
  waitSeconds: number;
  sending: boolean;
  onResend: () => void;
  onChangeNumber: () => void;
}) {
  const t = useTranslations('booking.verify');
  const f = useFormatters();

  return (
    <div data-testid="booking-verify" className="flex flex-col gap-3 rounded-14 border border-hairline bg-paper-alt p-4">
      <p className="text-13.5 leading-1.55 text-ink">{t('sent', { phone: f.digits(`0${phone.slice(3)}`), minutesText: f.number(CODE_MINUTES) })}</p>
      <Field label={t('code')} error={note?.tone === 'error' ? note.text : null} variant="form">
        <input
          value={code}
          onChange={(event) => onCode(event.target.value)}
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={6}
          placeholder="••••••"
          aria-invalid={note?.tone === 'error'}
          className={`${controlClass(note?.tone === 'error', 'form')} max-w-52 font-display tracking-[0.3em]`}
        />
      </Field>
      {note?.tone === 'info' ? (
        <p role="status" className="-mt-1 text-12.5 text-muted">
          {note.text}
        </p>
      ) : null}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-13">
        {waitSeconds > 0 ? (
          <span className="text-muted">{t('resendIn', { secondsText: f.number(waitSeconds) })}</span>
        ) : (
          <button type="button" disabled={sending} onClick={onResend} className="cursor-pointer font-semibold text-blue underline disabled:cursor-wait disabled:opacity-60">
            {sending ? t('sending') : t('resend')}
          </button>
        )}
        <button type="button" onClick={onChangeNumber} className="cursor-pointer font-semibold text-blue underline">
          {t('changeNumber')}
        </button>
      </div>
    </div>
  );
}
