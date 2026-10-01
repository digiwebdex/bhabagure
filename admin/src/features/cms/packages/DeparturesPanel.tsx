import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, Switch, TextArea, TextInput } from '../../../components/ui/fields'
import { Badge, Loading } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Data, Departure } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { useDepartures } from './api'

type DepartureForm = Pick<Departure, 'departs_on' | 'returns_on' | 'seats_total' | 'price' | 'is_guaranteed' | 'status' | 'notes'>

/**
 * Scheduled group departures. Seats booked come from confirmed bookings and can't be typed in. A group tour's
 * departures each have a price, and one is shown on the website's card (client, 2026-10-01; docs/departure-prices.md):
 * the radio beside each date, or "Automatic" for the next date with seats.
 */
export function DeparturesPanel({ packageId, durationDays, groupTour, packagePrice }: { packageId: number; durationDays: number; groupTour: boolean; packagePrice: number | null }) {
  const { t } = useTranslation()
  const { bdt, number, date } = useFormat()
  const toast = useToast()
  const queryClient = useQueryClient()
  const list = useDepartures(packageId)
  const [editing, setEditing] = useState<Departure | 'new' | null>(null)
  const feature = useMutation({
    mutationFn: ({ id, featured }: { id: number; featured: boolean }) => api.post(`admin/departures/${id}/feature`, { featured }),
    // The radio moves at once; the list is read back from the API either way.
    onMutate: ({ id, featured }) =>
      queryClient.setQueryData<Data<Departure[]>>(['departures', packageId], (old) =>
        old ? { ...old, data: old.data.map((departure) => ({ ...departure, is_featured: featured && departure.id === id })) } : old,
      ),
    onSuccess: () => toast(t('departures.featureSaved')),
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ['departures', packageId] }),
  })
  const featured = list.data?.data.find((departure) => departure.is_featured) ?? null

  return (
    <div className="flex flex-col gap-2.5">
      {list.isPending ? <Loading /> : list.isError ? <ErrorNotice error={list.error} /> : list.data.data.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('departures.none')}</p>
      ) : (
        <>
          {groupTour ? (
            <div className="flex flex-col gap-1 text-13" role="radiogroup" aria-label={t('departures.featureLabel')}>
              <span className="font-semibold">{t('departures.featureLabel')}</span>
              <span className="text-12 text-app-muted">{t('departures.featureHint')}</span>
              <label className="flex cursor-pointer items-center gap-2">
                <input type="radio" name={`featured-${packageId}`} checked={featured === null} disabled={feature.isPending} onChange={() => featured && feature.mutate({ id: featured.id, featured: false })} />
                {t('departures.featureAuto')}
              </label>
            </div>
          ) : null}
          <ul className="m-0 flex list-none flex-col gap-2 p-0">
            {list.data.data.map((departure) => {
              const percent = Math.min(100, Math.round((departure.seats_booked / departure.seats_total) * 100))
              return (
                <li key={departure.id} className="flex items-stretch gap-2">
                  {groupTour ? (
                    <label className="flex cursor-pointer items-center px-1" title={t('departures.featureOn', { date: date(departure.departs_on) })}>
                      <input
                        type="radio"
                        name={`featured-${packageId}`}
                        checked={departure.is_featured}
                        disabled={feature.isPending}
                        aria-label={t('departures.featureOn', { date: date(departure.departs_on) })}
                        onChange={() => feature.mutate({ id: departure.id, featured: true })}
                      />
                    </label>
                  ) : null}
                  <button type="button" onClick={() => setEditing(departure)} className="flex min-w-0 flex-1 cursor-pointer flex-col gap-1.5 rounded-10 border-0 bg-app-surface-2 px-3 py-2.5 text-left text-14 text-app-text">
                    <span className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-display font-semibold">{date(departure.departs_on)}{departure.returns_on ? ` → ${date(departure.returns_on)}` : ''}</span>
                      <span className="flex gap-1.5">
                        {departure.is_featured ? <Badge tone="orange">{t('departures.onCard')}</Badge> : null}
                        {departure.is_guaranteed ? <Badge tone="green">{t('departures.guaranteed')}</Badge> : null}
                        <Badge tone={departure.status === 'scheduled' ? 'blue' : 'slate'}>{t(`departures.status.${departure.status}`)}</Badge>
                      </span>
                    </span>
                    {groupTour ? (
                      <span className="text-13">
                        {departure.price !== null ? t('departures.pricePerPerson', { price: bdt(departure.price) }) : t('departures.packagePrice', { price: packagePrice !== null ? bdt(packagePrice) : '—' })}
                      </span>
                    ) : null}
                    <span className="flex items-center gap-2.5">
                      <span className="h-1.5 flex-1 overflow-hidden rounded-3 bg-app-line">
                        <span className="block h-full bg-linear-90/srgb from-blue to-orange" style={{ width: `${percent}%` }} />
                      </span>
                      <span className="text-12 whitespace-nowrap text-app-muted">{t('departures.seats', { booked: number(departure.seats_booked), total: number(departure.seats_total) })}</span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
          <ErrorNotice error={feature.error} />
        </>
      )}
      <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => setEditing('new')}>
        + {t('departures.add')}
      </button>
      {editing ? (
        <DepartureDialog packageId={packageId} durationDays={durationDays} groupTour={groupTour} packagePrice={packagePrice} departure={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
      ) : null}
    </div>
  )
}

function addDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

function DepartureDialog({ packageId, durationDays, groupTour, packagePrice, departure, onClose }: {
  packageId: number
  durationDays: number
  groupTour: boolean
  packagePrice: number | null
  departure: Departure | null
  onClose: () => void
}) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const [form, setForm] = useState<DepartureForm>(
    departure
      ? { departs_on: departure.departs_on, returns_on: departure.returns_on, seats_total: departure.seats_total, price: departure.price, is_guaranteed: departure.is_guaranteed, status: departure.status, notes: departure.notes }
      : { departs_on: '', returns_on: null, seats_total: 20, price: null, is_guaranteed: false, status: 'scheduled', notes: '' },
  )
  const done = (message: string) => {
    void queryClient.invalidateQueries({ queryKey: ['departures', packageId] })
    toast(message)
    onClose()
  }
  const save = useMutation({
    mutationFn: () => (departure ? api.put(`admin/departures/${departure.id}`, form) : api.post(`admin/packages/${packageId}/departures`, form)),
    onSuccess: () => done(t('common.saved')),
  })
  const remove = useMutation({ mutationFn: () => api.delete(`admin/departures/${departure!.id}`), onSuccess: () => done(t('common.deleted')) })
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={departure ? t('departures.edit') : t('departures.add')}>
      <Pair>
        <TextInput
          label={t('departures.departsOn')}
          type="date"
          value={form.departs_on}
          onChange={(departs_on) => setForm((current) => ({ ...current, departs_on, returns_on: current.returns_on || !departs_on ? current.returns_on : addDays(departs_on, Math.max(0, durationDays - 1)) }))}
          error={error('departs_on')}
        />
        <TextInput label={t('departures.returnsOn')} type="date" value={form.returns_on} onChange={(returns_on) => setForm({ ...form, returns_on: returns_on || null })} error={error('returns_on')} />
      </Pair>
      <Pair>
        <NumberInput label={t('departures.seatsTotal')} value={form.seats_total} onChange={(seats_total) => setForm({ ...form, seats_total: seats_total ?? 0 })} error={error('seats_total')} />
        <SelectInput
          label={t('common.status')}
          value={form.status}
          onChange={(status) => setForm({ ...form, status: status as Departure['status'] })}
          options={(['scheduled', 'closed', 'departed', 'cancelled'] as const).map((value) => ({ value, label: t(`departures.status.${value}`) }))}
        />
      </Pair>
      {groupTour ? (
        // This date's own price per person; blank keeps the package's (docs/departure-prices.md).
        <NumberInput
          label={t('departures.price')}
          value={form.price}
          onChange={(price) => setForm({ ...form, price: price && price > 0 ? price : null })}
          preview={(value) => bdt(value)}
          hint={t('departures.priceHint', { price: packagePrice !== null ? bdt(packagePrice) : '—' })}
          error={error('price')}
        />
      ) : null}
      <Switch label={t('departures.guaranteed')} hint={t('departures.guaranteedHint')} checked={form.is_guaranteed} onChange={(is_guaranteed) => setForm({ ...form, is_guaranteed })} />
      <TextArea label={t('departures.notes')} value={form.notes} onChange={(notes) => setForm({ ...form, notes })} error={error('notes')} />
      <ErrorNotice error={remove.error ?? (save.error instanceof ApiError && save.error.status === 422 ? null : save.error)} />
      <div className="flex flex-wrap justify-between gap-2">
        {departure ? (
          <button type="button" className={buttonClass('danger')} onClick={async () => (await confirm(t('departures.confirmDelete'))) && remove.mutate()}>
            {t('common.delete')}
          </button>
        ) : <span />}
        <span className="flex gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>{t('common.cancel')}</button>
          <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</button>
        </span>
      </div>
      {confirmDialog}
    </Dialog>
  )
}
