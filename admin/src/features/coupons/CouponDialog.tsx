import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Field, NumberInput, SelectInput, Switch, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { useCouponOptions, useSaveCoupon, type Coupon, type CouponForm } from './api'

const DHAKA_INPUT = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** A UTC timestamp as the date-and-time field shows it: Dhaka time, "2026-10-01T10:00". */
function toDhakaInput(iso: string | null): string {
  if (!iso) return ''
  const part = Object.fromEntries(DHAKA_INPUT.formatToParts(new Date(iso)).map((p) => [p.type, p.value]))
  return `${part.year}-${part.month}-${part.day}T${part.hour}:${part.minute}`
}

function formFor(coupon: Coupon | null): CouponForm {
  return coupon
    ? {
        code: coupon.code,
        name: coupon.name,
        kind: coupon.kind,
        channel: coupon.channel,
        discount_type: coupon.discount_type,
        discount_value: coupon.discount_value,
        max_discount_amount: coupon.max_discount_amount,
        min_booking_amount: coupon.min_booking_amount,
        starts_at: toDhakaInput(coupon.starts_at) || null,
        ends_at: toDhakaInput(coupon.ends_at) || null,
        usage_limit: coupon.usage_limit,
        per_customer_limit: coupon.per_customer_limit,
        applies_to: coupon.applies_to,
        package_ids: coupon.packages.map((p) => p.id),
        passport_number: coupon.passport_number,
        holder_name: coupon.holder_name,
        is_active: coupon.is_active,
        notes: coupon.notes,
      }
    : {
        code: '',
        name: '',
        kind: 'public',
        channel: null,
        discount_type: 'percent',
        discount_value: null,
        max_discount_amount: null,
        min_booking_amount: null,
        starts_at: null,
        ends_at: null,
        usage_limit: null,
        // One use per customer unless staff say otherwise.
        per_customer_limit: 1,
        applies_to: 'all',
        package_ids: [],
        passport_number: null,
        holder_name: null,
        is_active: true,
        notes: null,
      }
}

/**
 * Create or edit a coupon (docs/coupons.md §2.6). An edit reaches later bookings only: a booking keeps the terms it was
 * given. A used coupon keeps its code — it is on bookings and invoices.
 */
export function CouponDialog({ coupon, open, onClose }: { coupon: Coupon | null; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { bdt, percent } = useFormat()
  const options = useCouponOptions(open)
  const save = useSaveCoupon()
  const [form, setForm] = useState<CouponForm>(() => formFor(coupon))
  const set = (patch: Partial<CouponForm>) => setForm((current) => ({ ...current, ...patch }))
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)
  const percentOff = form.discount_type === 'percent'

  const submit = () =>
    save.mutate(
      {
        id: coupon?.id ?? null,
        form: {
          ...form,
          code: form.code.trim().toUpperCase(),
          // A cap means something only for a percentage; a passport only for a passport coupon.
          max_discount_amount: percentOff ? form.max_discount_amount : null,
          passport_number: form.kind === 'passport' ? form.passport_number : null,
          holder_name: form.kind === 'passport' ? form.holder_name : null,
          package_ids: form.applies_to === 'packages' ? form.package_ids : [],
        },
      },
      { onSuccess: (response) => { toast(t(coupon ? 'coupons.saved' : 'coupons.created', { code: response.data.code })); onClose() } },
    )

  return (
    <Dialog open={open} onClose={onClose} title={coupon ? t('coupons.editTitle', { code: coupon.code }) : t('coupons.newTitle')} wide>
      <form
        className="flex flex-col gap-4"
        data-testid="coupon-form"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        <div className="grid-auto-fit-260 grid gap-3">
          <TextInput
            label={t('coupons.fields.code')}
            value={form.code}
            onChange={(code) => set({ code: code.toUpperCase() })}
            error={error('code')}
            hint={coupon?.ever_used ? t('coupons.codeLocked') : t('coupons.codeHint')}
            disabled={coupon?.ever_used}
            autoCapitalize="characters"
            maxLength={30}
          />
          <TextInput label={t('coupons.fields.name')} value={form.name} onChange={(name) => set({ name })} error={error('name')} hint={t('coupons.nameHint')} maxLength={120} />
        </div>

        <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
          <legend className="mb-1.5 text-13 text-app-muted">{t('coupons.fields.kind')}</legend>
          <div className="grid-auto-fit-260 grid gap-2.5">
            {(['public', 'passport'] as const).map((kind) => (
              <label key={kind} className={`flex cursor-pointer items-start gap-2.5 rounded-12 border px-3.5 py-3 text-13 ${form.kind === kind ? 'border-blue bg-blue-tint' : 'border-app-line'}`}>
                <input type="radio" name="coupon-kind" value={kind} checked={form.kind === kind} onChange={() => set({ kind })} className="mt-0.5 accent-blue" />
                <span className="flex flex-col gap-0.5">
                  <strong className="font-semibold">{t(`coupons.kinds.${kind}`)}</strong>
                  <span className="text-12 text-app-muted">{t(`coupons.kindNotes.${kind}`)}</span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>
        {form.kind === 'passport' ? (
          <div className="grid-auto-fit-260 grid gap-3">
            <TextInput
              label={t('coupons.fields.passport')}
              value={form.passport_number}
              onChange={(value) => set({ passport_number: value.toUpperCase() || null })}
              error={error('passport_number')}
              hint={t('coupons.passportHint')}
              autoCapitalize="characters"
              autoComplete="off"
            />
            <TextInput label={t('coupons.fields.holder')} value={form.holder_name} onChange={(value) => set({ holder_name: value || null })} error={error('holder_name')} hint={t('coupons.holderHint')} />
          </div>
        ) : null}

        <div className="grid-auto-fit-200 grid gap-3">
          <SelectInput
            label={t('coupons.fields.discountType')}
            value={form.discount_type}
            onChange={(value) => set({ discount_type: value as CouponForm['discount_type'] })}
            options={(['percent', 'fixed'] as const).map((value) => ({ value, label: t(`coupons.discountTypes.${value}`) }))}
          />
          <NumberInput
            label={percentOff ? t('coupons.fields.percent') : t('coupons.fields.amount')}
            value={form.discount_value}
            onChange={(discount_value) => set({ discount_value })}
            error={error('discount_value')}
            preview={(value) => (percentOff ? t('coupons.offPercent', { value: percent(value) }) : t('coupons.offAmount', { amount: bdt(value) }))}
          />
          {percentOff ? (
            <NumberInput
              label={t('coupons.fields.maxDiscount')}
              value={form.max_discount_amount}
              onChange={(max_discount_amount) => set({ max_discount_amount })}
              error={error('max_discount_amount')}
              hint={t('coupons.maxHint')}
            />
          ) : null}
          <NumberInput
            label={t('coupons.fields.minAmount')}
            value={form.min_booking_amount}
            onChange={(min_booking_amount) => set({ min_booking_amount })}
            error={error('min_booking_amount')}
            hint={t('coupons.minHint')}
          />
        </div>

        <div className="grid-auto-fit-200 grid gap-3">
          <Field label={t('coupons.fields.startsAt')} error={error('starts_at')} hint={t('coupons.dhakaTime')}>
            {(id, describedBy) => (
              <input id={id} type="datetime-local" aria-describedby={describedBy} value={form.starts_at ?? ''} onChange={(event) => set({ starts_at: event.target.value || null })} className={controlClass(!!error('starts_at'))} />
            )}
          </Field>
          <Field label={t('coupons.fields.endsAt')} error={error('ends_at')} hint={t('coupons.dhakaTime')}>
            {(id, describedBy) => (
              <input id={id} type="datetime-local" aria-describedby={describedBy} value={form.ends_at ?? ''} min={form.starts_at ?? undefined} onChange={(event) => set({ ends_at: event.target.value || null })} className={controlClass(!!error('ends_at'))} />
            )}
          </Field>
          <NumberInput label={t('coupons.fields.usageLimit')} value={form.usage_limit} onChange={(usage_limit) => set({ usage_limit })} error={error('usage_limit')} hint={t('coupons.noLimitHint')} />
          <NumberInput label={t('coupons.fields.perCustomer')} value={form.per_customer_limit} onChange={(per_customer_limit) => set({ per_customer_limit })} error={error('per_customer_limit')} hint={t('coupons.noLimitHint')} />
        </div>

        <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
          <legend className="mb-1.5 text-13 text-app-muted">{t('coupons.fields.appliesTo')}</legend>
          {(['all', 'packages'] as const).map((value) => (
            <label key={value} className="flex cursor-pointer items-center gap-2 text-13">
              <input type="radio" name="coupon-applies" value={value} checked={form.applies_to === value} onChange={() => set({ applies_to: value })} className="accent-blue" />
              {t(`coupons.appliesTo.${value}`)}
            </label>
          ))}
          {form.applies_to === 'packages' ? (
            <div className="flex max-h-52 flex-col gap-1.5 overflow-y-auto rounded-10 border border-app-line px-3 py-2.5">
              {options.data?.data.packages.map((pkg) => (
                <label key={pkg.id} className="flex cursor-pointer items-center gap-2 text-13">
                  <input
                    type="checkbox"
                    checked={form.package_ids.includes(pkg.id)}
                    onChange={(event) => set({ package_ids: event.target.checked ? [...form.package_ids, pkg.id] : form.package_ids.filter((id) => id !== pkg.id) })}
                    className="accent-blue"
                  />
                  <span>{pkg.title}</span>
                  {pkg.published ? null : <span className="text-12 text-app-muted">· {t('coupons.unpublished')}</span>}
                </label>
              ))}
            </div>
          ) : null}
          {error('package_ids') ? <span role="alert" className="text-12 font-semibold text-red">{error('package_ids')}</span> : null}
        </fieldset>

        <div className="grid-auto-fit-260 grid items-start gap-3">
          <SelectInput
            label={t('coupons.fields.channel')}
            value={form.channel ?? ''}
            onChange={(value) => set({ channel: value || null })}
            options={[{ value: '', label: t('coupons.noChannel') }, ...(options.data?.data.channels ?? []).map((value) => ({ value, label: t(`coupons.channels.${value}`) }))]}
          />
          <div className="pt-6">
            <Switch label={t('coupons.fields.active')} checked={form.is_active} onChange={(is_active) => set({ is_active })} hint={t('coupons.activeHint')} />
          </div>
        </div>
        <TextArea label={t('coupons.fields.notes')} value={form.notes} onChange={(notes) => set({ notes: notes || null })} error={error('notes')} rows={2} />

        {save.error && !(save.error instanceof ApiError && Object.keys(save.error.errors).length > 0) ? <ErrorNotice error={save.error} /> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('primary')} disabled={save.isPending}>
            {save.isPending ? t('common.saving') : coupon ? t('coupons.save') : t('coupons.create')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
