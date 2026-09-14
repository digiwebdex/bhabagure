import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, Switch, TextArea, TextInput } from '../../../components/ui/fields'
import { Badge, Loading } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Departure } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { useDepartures } from './api'

type DepartureForm = Pick<Departure, 'departs_on' | 'returns_on' | 'seats_total' | 'is_guaranteed' | 'status' | 'notes'>

/** Scheduled group departures. Seats booked come from confirmed bookings and can't be typed in. */
export function DeparturesPanel({ packageId, durationDays }: { packageId: number; durationDays: number }) {
  const { t } = useTranslation()
  const { number, date } = useFormat()
  const list = useDepartures(packageId)
  const [editing, setEditing] = useState<Departure | 'new' | null>(null)

  return (
    <div className="flex flex-col gap-2.5">
      {list.isPending ? <Loading /> : list.isError ? <ErrorNotice error={list.error} /> : list.data.data.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('departures.none')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {list.data.data.map((departure) => {
            const percent = Math.min(100, Math.round((departure.seats_booked / departure.seats_total) * 100))
            return (
              <li key={departure.id}>
                <button type="button" onClick={() => setEditing(departure)} className="flex w-full cursor-pointer flex-col gap-1.5 rounded-10 border-0 bg-app-surface-2 px-3 py-2.5 text-left text-14 text-app-text">
                  <span className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-display font-semibold">{date(departure.departs_on)}{departure.returns_on ? ` → ${date(departure.returns_on)}` : ''}</span>
                    <span className="flex gap-1.5">
                      {departure.is_guaranteed ? <Badge tone="green">{t('departures.guaranteed')}</Badge> : null}
                      <Badge tone={departure.status === 'scheduled' ? 'blue' : 'slate'}>{t(`departures.status.${departure.status}`)}</Badge>
                    </span>
                  </span>
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
      )}
      <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => setEditing('new')}>
        + {t('departures.add')}
      </button>
      {editing ? <DepartureDialog packageId={packageId} durationDays={durationDays} departure={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  )
}

function addDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

function DepartureDialog({ packageId, durationDays, departure, onClose }: { packageId: number; durationDays: number; departure: Departure | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const { confirm, element: confirmDialog } = useConfirm()
  const [form, setForm] = useState<DepartureForm>(
    departure
      ? { departs_on: departure.departs_on, returns_on: departure.returns_on, seats_total: departure.seats_total, is_guaranteed: departure.is_guaranteed, status: departure.status, notes: departure.notes }
      : { departs_on: '', returns_on: null, seats_total: 20, is_guaranteed: false, status: 'scheduled', notes: '' },
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
