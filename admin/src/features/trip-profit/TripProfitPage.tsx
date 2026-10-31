import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { downloadTrips, TRIP_TYPES, tripTotals, useDeleteTrip, useSaveTrip, useTrips, type Trip, type TripFilters, type TripInput, type TripType } from './api'

const profitClass = (value: number) => (value > 0 ? 'text-green' : value < 0 ? 'text-red' : 'text-app-muted')

/**
 * Finance → Trip profit (docs/trip-profit.md): each trip's flight, land package and other costs, what was collected
 * from the travellers and the profit, entered by hand — on its own, not read from bookings or invoices. The totals
 * follow the period, search and type chosen; the CSV is the same list.
 */
export function TripProfitPage() {
  const { t } = useTranslation()
  const { bdt, number, date, month } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const thisMonth = todayInDhaka().slice(0, 7)
  const [filters, setFilters] = useState<TripFilters>({ period: thisMonth, search: '', type: '', page: 1 })
  const [typed, setTyped] = useState('')
  const [editing, setEditing] = useState<Trip | 'new' | null>(null)
  const [exporting, setExporting] = useState(false)
  const trips = useTrips(filters)
  const remove = useDeleteTrip()
  const manage = can('trip_profit.manage')

  // The search applies once typing pauses.
  useEffect(() => {
    const timer = window.setTimeout(() => setFilters((current) => (current.search === typed ? current : { ...current, search: typed, page: 1 })), 300)
    return () => window.clearTimeout(timer)
  }, [typed])

  const set = (patch: Partial<TripFilters>) => setFilters((current) => ({ ...current, ...patch, page: patch.page ?? 1 }))
  const months = [...new Set([thisMonth, ...(trips.data?.months ?? [])])].sort().reverse()
  const years = [...new Set(months.map((m) => m.slice(0, 4)))]
  const periods = [
    { value: 'all', label: t('tripProfit.allTime') },
    ...years.flatMap((year) => [
      { value: year, label: t('tripProfit.wholeYear', { year }) },
      ...months.filter((m) => m.startsWith(year)).map((m) => ({ value: m, label: month(m) })),
    ]),
  ]
  const summary = trips.data?.summary

  const onDelete = async (trip: Trip) => {
    if (!(await confirm(t('tripProfit.confirmDelete', { title: trip.title })))) return
    remove.mutate(trip.id, { onSuccess: () => toast(t('common.deleted')) })
  }

  const onExport = async () => {
    setExporting(true)
    try {
      await downloadTrips(filters)
    } catch {
      toast(t('tripProfit.exportFailed'), 'error')
    } finally {
      setExporting(false)
    }
  }

  return (
    <>
      <PageHeader
        title={t('tripProfit.title')}
        subtitle={t('tripProfit.subtitle')}
        actions={
          <>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => void onExport()} aria-disabled={exporting} data-testid="trip-export">
              {exporting ? t('tripProfit.exporting') : t('tripProfit.export')}
            </button>
            {manage ? (
              <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setEditing('new')}>
                + {t('tripProfit.add')}
              </button>
            ) : null}
          </>
        }
      />

      <div className="mb-4.5 flex flex-wrap items-end gap-3">
        <SelectInput label={t('tripProfit.period')} value={filters.period} onChange={(value) => set({ period: value })} options={periods} className="w-56" />
        <TextInput label={t('tripProfit.search')} value={typed} onChange={setTyped} placeholder={t('tripProfit.searchPlaceholder')} className="w-64" type="search" />
        <div className="pb-1">
          <Chips
            label={t('tripProfit.type')}
            value={filters.type}
            onChange={(value) => set({ type: value })}
            options={[{ value: '' as const, label: t('tripProfit.allTypes') }, ...TRIP_TYPES.map((type) => ({ value: type, label: t(`tripProfit.types.${type}`) }))]}
          />
        </div>
      </div>

      <div className="mb-4.5 grid grid-cols-2 gap-3.5 lg:grid-cols-4" data-testid="trip-summary">
        <Stat label={t('tripProfit.totalTrips')} value={summary ? number(summary.trips) : '…'} note={summary ? t('tripProfit.paxNote', { count: summary.pax, pax: number(summary.pax) }) : ''} />
        <Stat label={t('tripProfit.totalCollected')} value={summary ? bdt(summary.collected) : '…'} />
        <Stat label={t('tripProfit.totalCost')} value={summary ? bdt(summary.cost) : '…'} />
        <Stat label={t('tripProfit.totalProfit')} value={summary ? bdt(summary.profit) : '…'} valueClass={summary ? profitClass(summary.profit) : ''} />
      </div>

      <Card padded={false}>
        {trips.isPending ? (
          <Loading />
        ) : trips.isError ? (
          <div className="p-5">
            <ErrorNotice error={trips.error} />
          </div>
        ) : trips.data.data.length === 0 ? (
          <EmptyState title={t('tripProfit.empty')} note={manage ? t('tripProfit.emptyNote') : undefined} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-200 border-collapse text-13.5" data-testid="trip-table">
              <thead>
                <tr className="border-b border-app-line text-left text-12 text-app-muted">
                  <th className="px-4 py-3 font-semibold">{t('tripProfit.columns.trip')}</th>
                  <th className="px-3 py-3 font-semibold">{t('tripProfit.columns.date')}</th>
                  <th className="px-3 py-3 text-right font-semibold">{t('tripProfit.columns.pax')}</th>
                  <th className="px-3 py-3 text-right font-semibold">{t('tripProfit.columns.cost')}</th>
                  <th className="px-3 py-3 text-right font-semibold">{t('tripProfit.columns.collected')}</th>
                  <th className="px-3 py-3 text-right font-semibold">{t('tripProfit.columns.profit')}</th>
                  {manage ? <th className="px-4 py-3 text-right font-semibold">{t('tripProfit.columns.actions')}</th> : null}
                </tr>
              </thead>
              <tbody>
                {trips.data.data.map((trip) => (
                  <tr key={trip.id} className="border-b border-app-line last:border-b-0" data-testid="trip-row">
                    <td className="px-4 py-3">
                      <span className="flex flex-col gap-1">
                        <span className="font-semibold">{trip.title}</span>
                        <span className="flex flex-wrap items-center gap-1.5">
                          {trip.trip_type ? <Badge tone={trip.trip_type === 'group_tour' ? 'blue' : trip.trip_type === 'custom_trip' ? 'orange' : 'slate'}>{t(`tripProfit.types.${trip.trip_type}`)}</Badge> : null}
                          {trip.notes ? (
                            <span className="line-clamp-1 max-w-72 text-12 text-app-muted" title={trip.notes}>
                              {trip.notes}
                            </span>
                          ) : null}
                        </span>
                      </span>
                    </td>
                    <td className="px-3 py-3 whitespace-nowrap">{date(trip.trip_date)}</td>
                    <td className="px-3 py-3 text-right">{number(trip.pax)}</td>
                    <td className="px-3 py-3 text-right">
                      <CostBreakdown trip={trip} />
                    </td>
                    <td className="px-3 py-3 text-right whitespace-nowrap">{bdt(trip.total_collected_amount)}</td>
                    <td className={`px-3 py-3 text-right font-semibold whitespace-nowrap ${profitClass(trip.profit)}`} data-testid="trip-profit">
                      {bdt(trip.profit)}
                    </td>
                    {manage ? (
                      <td className="px-4 py-3 text-right whitespace-nowrap">
                        <button type="button" className={buttonClass('ghost', 'sm')} onClick={() => setEditing(trip)} aria-label={t('tripProfit.editNamed', { title: trip.title })}>
                          {t('tripProfit.edit')}
                        </button>
                        <button type="button" className={buttonClass('ghost', 'sm', 'text-red')} onClick={() => void onDelete(trip)} aria-label={t('tripProfit.deleteNamed', { title: trip.title })}>
                          {t('common.delete')}
                        </button>
                      </td>
                    ) : null}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {trips.data && trips.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <span className="text-app-muted">{t('tripProfit.pageOf', { page: trips.data.meta.current_page, pages: trips.data.meta.last_page })}</span>
            <span className="flex gap-2">
              <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
                {t('common.previous')}
              </button>
              <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= trips.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
                {t('common.next')}
              </button>
            </span>
          </div>
        ) : null}
      </Card>
      <ErrorNotice error={remove.error} />

      {editing ? <TripDialog trip={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
      {confirmDialog}
    </>
  )
}

function Stat({ label, value, note, valueClass = '' }: { label: string; value: string; note?: string; valueClass?: string }) {
  return (
    <div className="flex flex-col gap-1.5 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">
      <span className="text-13 text-app-muted">{label}</span>
      <span className={`font-display text-24 font-extrabold tracking-heading ${valueClass}`}>{value}</span>
      {note ? <span className="text-12 text-app-muted">{note}</span> : null}
    </div>
  )
}

/** The total, and on click (or keyboard focus and Enter) its flight, land package and other costs. */
function CostBreakdown({ trip }: { trip: Trip }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [open, setOpen] = useState(false)

  return (
    <span
      className="relative inline-block"
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOpen(false)
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape') setOpen(false)
      }}
    >
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="cursor-pointer border-0 bg-transparent p-0 text-right font-[inherit] whitespace-nowrap text-app-text underline decoration-app-line decoration-dotted underline-offset-4 hover:decoration-blue"
        data-testid="trip-cost"
      >
        {bdt(trip.total_cost)}
      </button>
      {open ? (
        <span role="dialog" aria-label={t('tripProfit.breakdown')} className="absolute top-full right-0 z-20 mt-1.5 flex w-64 flex-col gap-1 rounded-12 border border-app-line bg-app-surface p-3 text-left shadow-raised" data-testid="trip-breakdown">
          {(
            [
              ['flight', trip.flight_cost],
              ['land', trip.land_package_cost],
              ['other', trip.other_cost],
            ] as const
          ).map(([key, value]) => (
            <span key={key} className="flex justify-between gap-3 text-13">
              <span className="text-app-muted">{t(`tripProfit.costs.${key}`)}</span>
              <span>{bdt(value)}</span>
            </span>
          ))}
          <span className="mt-1 flex justify-between gap-3 border-t border-app-line pt-1.5 text-13 font-semibold">
            <span>{t('tripProfit.columns.cost')}</span>
            <span>{bdt(trip.total_cost)}</span>
          </span>
        </span>
      ) : null}
    </span>
  )
}

function TripDialog({ trip, onClose }: { trip: Trip | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const save = useSaveTrip()
  const [form, setForm] = useState<TripInput>(() => ({
    title: trip?.title ?? '',
    trip_type: trip?.trip_type ?? null,
    trip_date: trip?.trip_date ?? todayInDhaka(),
    pax: trip?.pax ?? null,
    flight_cost: trip?.flight_cost ?? null,
    land_package_cost: trip?.land_package_cost ?? null,
    other_cost: trip?.other_cost ?? null,
    total_collected_amount: trip?.total_collected_amount ?? null,
    notes: trip?.notes ?? '',
  }))
  const set = <K extends keyof TripInput>(key: K, value: TripInput[K]) => setForm((current) => ({ ...current, [key]: value }))
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)
  const { cost, profit } = tripTotals(form)
  const ready = form.title.trim() !== '' && form.trip_date !== '' && form.pax !== null && form.total_collected_amount !== null

  return (
    <Dialog open onClose={onClose} title={trip ? t('tripProfit.editTrip') : t('tripProfit.add')} wide>
      <form
        className="flex flex-col gap-3"
        data-testid="trip-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (save.isPending || !ready) return
          save.mutate(
            { id: trip?.id, input: form },
            {
              onSuccess: () => {
                toast(t('common.saved'))
                onClose()
              },
            },
          )
        }}
      >
        <TextInput label={t('tripProfit.fields.title')} value={form.title} onChange={(value) => set('title', value)} error={error('title')} placeholder={t('tripProfit.fields.titlePlaceholder')} autoFocus />
        <Pair>
          <SelectInput
            label={t('tripProfit.fields.type')}
            value={form.trip_type ?? ''}
            onChange={(value) => set('trip_type', value === '' ? null : (value as TripType))}
            options={[{ value: '', label: t('tripProfit.fields.noType') }, ...TRIP_TYPES.map((type) => ({ value: type, label: t(`tripProfit.types.${type}`) }))]}
            error={error('trip_type')}
          />
          <TextInput label={t('tripProfit.fields.date')} type="date" value={form.trip_date} onChange={(value) => set('trip_date', value)} error={error('trip_date')} hint={t('tripProfit.fields.dateHint')} />
        </Pair>
        <NumberInput label={t('tripProfit.fields.pax')} value={form.pax} onChange={(value) => set('pax', value)} error={error('pax')} className="max-w-40" />
        <Pair>
          <NumberInput label={t('tripProfit.costs.flight')} value={form.flight_cost} onChange={(value) => set('flight_cost', value)} error={error('flight_cost')} hint={t('tripProfit.fields.flightHint')} />
          <NumberInput label={t('tripProfit.costs.land')} value={form.land_package_cost} onChange={(value) => set('land_package_cost', value)} error={error('land_package_cost')} hint={t('tripProfit.fields.landHint')} />
        </Pair>
        <Pair>
          <NumberInput label={t('tripProfit.costs.other')} value={form.other_cost} onChange={(value) => set('other_cost', value)} error={error('other_cost')} hint={t('tripProfit.fields.otherHint')} />
          <NumberInput label={t('tripProfit.fields.collected')} value={form.total_collected_amount} onChange={(value) => set('total_collected_amount', value)} error={error('total_collected_amount')} hint={t('tripProfit.fields.collectedHint')} />
        </Pair>

        {/* Worked out as the figures are typed; the API saves its own, the same way. */}
        <div className="grid grid-cols-2 gap-3 rounded-13 bg-app-surface-2 p-4" aria-live="polite" data-testid="trip-preview">
          <span className="flex flex-col gap-0.5">
            <span className="text-12 text-app-muted">{t('tripProfit.columns.cost')}</span>
            <span className="font-display text-20 font-extrabold" data-testid="trip-preview-cost">
              {bdt(cost)}
            </span>
          </span>
          <span className="flex flex-col gap-0.5">
            <span className="text-12 text-app-muted">{profit < 0 ? t('tripProfit.loss') : t('tripProfit.columns.profit')}</span>
            <span className={`font-display text-20 font-extrabold ${profitClass(profit)}`} data-testid="trip-preview-profit">
              {bdt(profit)}
            </span>
            {form.pax ? <span className="text-12 text-app-muted">{t('tripProfit.perPerson', { amount: bdt(Math.round((profit / form.pax) * 100) / 100) })}</span> : null}
          </span>
        </div>

        <TextArea label={t('tripProfit.fields.notes')} value={form.notes} onChange={(value) => set('notes', value)} error={error('notes')} rows={3} />
        {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('primary')} aria-disabled={save.isPending || !ready}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
