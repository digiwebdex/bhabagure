'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { controlClass, Field } from '@/components/ui/Field';
import { Modal, ModalClose } from '@/components/ui/Modal';
import { submitReview } from '@/lib/reviews-api';
import { useFormatters } from '@/lib/use-formatters';
import { normalizeBdMobile } from '@/lib/validators';

/** Matches ReviewSubmissions in the API. */
const MAX_PHOTOS = 5;
const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const MIN_TEXT = 20;
const MAX_TEXT = 1500;
const OTHER = '__other__';

type Errors = Partial<Record<'name' | 'phone' | 'trip' | 'travelled_month' | 'rating' | 'review' | 'photos' | 'form', string>>;

/**
 * "Share your trip" (docs/customer-reviews.md): a customer rates their trip, writes a review and adds up to five photos.
 * It waits for the office to check it, and the form says so. From a package's page that package is already chosen.
 */
export function ReviewForm({ packageSlug, variant = 'cta' }: { packageSlug?: string; variant?: 'cta' | 'outlineInk' }) {
  const t = useTranslations('reviewForm');
  const tc = useTranslations('common');
  const locale = useLocale() as 'bn' | 'en';
  const f = useFormatters();
  const { packages } = useSiteContent();
  const titleId = useId();
  const [open, setOpen] = useState(false);
  const [sent, setSent] = useState(false);
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [form, setForm] = useState({ name: '', phone: '', trip: packageSlug ?? '', tripOther: '', month: '', rating: 0, review: '', company: '' });
  const [photos, setPhotos] = useState<{ file: File; url: string }[]>([]);
  const thisMonth = new Date().toISOString().slice(0, 7);

  // Previews are object URLs: each is let go when its photo is removed, the rest when the form goes away.
  const previews = useRef<string[]>([]);
  useEffect(() => {
    previews.current = photos.map((photo) => photo.url);
  }, [photos]);
  useEffect(() => () => previews.current.forEach((url) => URL.revokeObjectURL(url)), []);
  const removePhoto = (index: number) => {
    URL.revokeObjectURL(photos[index].url);
    setPhotos(photos.filter((_, i) => i !== index));
  };

  const set = (patch: Partial<typeof form>) => setForm((current) => ({ ...current, ...patch }));

  const addPhotos = (files: FileList | null) => {
    if (!files) return;
    const picked = [...files].filter((file) => /^image\/(jpeg|png)$/.test(file.type));
    const tooBig = picked.some((file) => file.size > PHOTO_MAX_BYTES);
    const room = MAX_PHOTOS - photos.length;
    const kept = picked.filter((file) => file.size <= PHOTO_MAX_BYTES).slice(0, room);
    setPhotos([...photos, ...kept.map((file) => ({ file, url: URL.createObjectURL(file) }))]);
    setErrors((current) => ({
      ...current,
      photos: tooBig ? t('photoTooBig') : picked.length > room ? t('tooManyPhotos', { maxText: f.number(MAX_PHOTOS) }) : picked.length < files.length ? t('photoType') : undefined,
    }));
  };

  const check = (): Errors => {
    const e: Errors = {};
    if (form.name.trim().length < 2) e.name = t('required');
    if (!normalizeBdMobile(form.phone)) e.phone = t('phoneInvalid');
    if (!form.trip || (form.trip === OTHER && !form.tripOther.trim())) e.trip = t('required');
    if (form.rating < 1) e.rating = t('pickRating');
    const length = form.review.trim().length;
    if (length < MIN_TEXT) e.review = t('tooShort', { minText: f.number(MIN_TEXT) });
    else if (length > MAX_TEXT) e.review = t('tooLong', { maxText: f.number(MAX_TEXT) });
    return e;
  };

  const send = async (event: FormEvent) => {
    event.preventDefault();
    const found = check();
    setErrors(found);
    if (Object.keys(found).length > 0) return;

    const body = new FormData();
    body.append('name', form.name.trim());
    body.append('phone', normalizeBdMobile(form.phone) ?? form.phone);
    if (form.trip === OTHER) body.append('trip', form.tripOther.trim());
    else body.append('package_slug', form.trip);
    if (form.month) body.append('travelled_month', form.month);
    body.append('rating', String(form.rating));
    body.append('review', form.review.trim());
    body.append('locale', locale);
    if (form.company) body.append('company', form.company);
    photos.forEach((photo) => body.append('photos[]', photo.file));

    setSending(true);
    const result = await submitReview(body);
    setSending(false);
    if (result.ok) {
      setSent(true);
      return;
    }
    if (result.reason === 'invalid') {
      setErrors({ ...result.errors, trip: result.errors.trip ?? result.errors.package_slug } as Errors);
      return;
    }
    setErrors({ form: t(result.reason === 'rate_limited' ? 'rateLimited' : 'failed') });
  };

  const close = () => {
    setOpen(false);
    if (sent) {
      setSent(false);
      setForm({ name: '', phone: '', trip: packageSlug ?? '', tripOther: '', month: '', rating: 0, review: '', company: '' });
      photos.forEach((photo) => URL.revokeObjectURL(photo.url));
      setPhotos([]);
    }
  };

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className={buttonClass(variant, 'md')}>
        ✍ {t('open')}
      </button>
      <Modal open={open} onClose={close} labelledBy={titleId} size="md" layer="detail">
        <div className="flex shrink-0 items-start justify-between gap-3.5 border-b border-hairline px-fluid-18-28 py-4.5">
          <div className="min-w-0">
            <h2 id={titleId} className="text-fluid-20-26 font-bold tracking-title">
              {t('title')}
            </h2>
            <p className="mt-0.75 text-13 text-muted">{t('subtitle')}</p>
          </div>
          <ModalClose onClick={close} label={tc('close')} />
        </div>

        {sent ? (
          <div className="flex flex-col items-center gap-3 px-fluid-18-28 py-10 text-center" role="status" data-testid="review-sent">
            <span aria-hidden className="text-40">🙏</span>
            <p className="text-18 font-semibold">{t('thanks')}</p>
            <p className="max-w-md text-14 text-muted">{t('thanksNote')}</p>
            <button type="button" onClick={close} className={buttonClass('outlineInk', 'md', 'mt-2')}>
              {tc('close')}
            </button>
          </div>
        ) : (
          <form onSubmit={(event) => void send(event)} noValidate className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-fluid-18-28 py-5">
            {/* Hidden from people; bots fill it in (the API stores nothing then). */}
            <input type="text" name="company" tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.company} onChange={(e) => set({ company: e.target.value })} className="hidden" />

            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-14 text-muted">{t('rating')}</legend>
              <div className="flex gap-1" role="radiogroup" aria-label={t('rating')}>
                {[1, 2, 3, 4, 5].map((stars) => (
                  <button
                    key={stars}
                    type="button"
                    role="radio"
                    aria-checked={form.rating === stars}
                    aria-label={t('stars', { count: stars, countText: f.number(stars) })}
                    onClick={() => set({ rating: stars })}
                    className={`cursor-pointer text-32 leading-none transition-transform hover:scale-110 ${stars <= form.rating ? 'text-orange' : 'text-hairline-hover'}`}
                  >
                    ★
                  </button>
                ))}
              </div>
              {errors.rating ? <span role="alert" className="text-12 font-semibold text-red">{errors.rating}</span> : null}
            </fieldset>

            <div className="grid-auto-fit-200 grid gap-3">
              <Field variant="form" label={t('name')} error={errors.name}>
                <input value={form.name} onChange={(e) => set({ name: e.target.value })} autoComplete="name" maxLength={120} className={controlClass(!!errors.name, 'form')} />
              </Field>
              <Field variant="form" label={t('phone')} error={errors.phone}>
                <input value={form.phone} onChange={(e) => set({ phone: e.target.value })} inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" className={controlClass(!!errors.phone, 'form')} />
              </Field>
            </div>
            <p className="-mt-2 text-12 text-muted">{t('phoneNote')}</p>

            <div className="grid-auto-fit-200 grid gap-3">
              <Field variant="form" label={t('trip')} error={errors.trip}>
                <select value={form.trip} onChange={(e) => set({ trip: e.target.value })} className={controlClass(!!errors.trip, 'form')}>
                  <option value="">{t('pickTrip')}</option>
                  {packages.map((pkg) => (
                    <option key={pkg.slug} value={pkg.slug}>
                      {pkg.title}
                    </option>
                  ))}
                  <option value={OTHER}>{t('otherTrip')}</option>
                </select>
              </Field>
              <Field variant="form" label={t('month')} error={errors.travelled_month}>
                <input type="month" value={form.month} max={thisMonth} onChange={(e) => set({ month: e.target.value })} className={controlClass(!!errors.travelled_month, 'form')} />
              </Field>
            </div>
            {form.trip === OTHER ? (
              <Field variant="form" label={t('otherTripName')} error={errors.trip}>
                <input value={form.tripOther} onChange={(e) => set({ tripOther: e.target.value })} maxLength={160} placeholder={t('otherTripPlaceholder')} className={controlClass(!!errors.trip, 'form')} />
              </Field>
            ) : null}

            <Field variant="form" label={t('review')} error={errors.review}>
              <textarea
                value={form.review}
                onChange={(e) => set({ review: e.target.value })}
                rows={5}
                maxLength={MAX_TEXT}
                placeholder={t('reviewPlaceholder')}
                className={`${controlClass(!!errors.review, 'form')} resize-y leading-1.55`}
              />
            </Field>
            <p className="-mt-2 text-right text-12 text-muted">
              {f.number(form.review.trim().length)} / {f.number(MAX_TEXT)}
            </p>

            <div className="flex flex-col gap-2">
              <span className="text-14 text-muted">{t('photos', { maxText: f.number(MAX_PHOTOS) })}</span>
              {photos.length > 0 ? (
                <ul className="m-0 flex list-none flex-wrap gap-2 p-0" data-testid="review-photo-previews">
                  {photos.map((photo, index) => (
                    <li key={photo.url} className="relative">
                      {/* eslint-disable-next-line @next/next/no-img-element -- a local preview of the chosen file */}
                      <img src={photo.url} alt={t('photoPreview', { n: f.number(index + 1) })} className="size-20 rounded-10 object-cover" />
                      <button
                        type="button"
                        onClick={() => removePhoto(index)}
                        aria-label={t('removePhoto', { n: f.number(index + 1) })}
                        className="absolute -top-1.5 -right-1.5 flex size-6 cursor-pointer items-center justify-center rounded-full bg-ink text-12 text-white"
                      >
                        ×
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
              {photos.length < MAX_PHOTOS ? (
                <label className={buttonClass('outlineInk', 'sm', 'self-start')}>
                  📷 {t('addPhotos')}
                  <input type="file" accept="image/jpeg,image/png" multiple onChange={(e) => { addPhotos(e.target.files); e.target.value = ''; }} className="sr-only" />
                </label>
              ) : null}
              <span className={`text-12 ${errors.photos ? 'font-semibold text-red' : 'text-muted'}`} role={errors.photos ? 'alert' : undefined}>
                {errors.photos ?? t('photosHint')}
              </span>
            </div>

            {errors.form ? <p role="alert" className="rounded-10 bg-orange-tint px-3 py-2.5 text-13 text-amber">{errors.form}</p> : null}
            <p className="text-12 text-muted">{t('moderationNote')}</p>
            <button type="submit" disabled={sending} className={buttonClass('cta', 'block')}>
              {sending ? t('sending') : t('send')}
            </button>
          </form>
        )}
      </Modal>
    </>
  );
}
