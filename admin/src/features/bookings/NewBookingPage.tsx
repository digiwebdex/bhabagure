import { defaultHotelCategory, gridCategories, invoiceTotals, quoteBooking, type HotelCategory, type RoomType } from '@bhabaghure/pricing'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Chips, Loading, PageHeader } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import type { BookingDetail, BookingFormOptions as Options } from './api'

type CustomerHit = { id: number; name: string; phone: string }

/** The package list's "Custom service" choice (docs/custom-service-bookings.md): no package slug is ever this. */
const CUSTOM = '__custom__'

/** One item of a custom service: its name and its price per person. */
type CustomItem = { title: string; price: number | null }

const emptyItem = (): CustomItem => ({ title: '', price: null })

/**
 * "+ New booking" (docs/phase-5-admin-core.md §4.3). Priced with @bhabaghure/pricing from the options the API sends —
 * the API re-prices with its PHP twin and refuses a total the staff member didn't see. Passports can follow later.
 */
export function NewBookingPage() {
  const { t } = useTranslation()
  const { bdt, number, date, digits, locale } = useFormat()
  const navigate = useNavigate()
  const toast = useToast()
  const options = useQuery({ queryKey: ['booking-options'], queryFn: ({ signal }) => api.get<Data<Options>>('admin/bookings/options', signal).then((r) => r.data) })

  const [mode, setMode] = useState<'new' | 'existing'>('new')
  const [customer, setCustomer] = useState({ name: '', phone: '', email: '', source: 'walk_in' })
  const [picked, setPicked] = useState<CustomerHit | null>(null)
  const [lookup, setLookup] = useState('')
  const [slug, setSlug] = useState('')
  const [travelDate, setTravelDate] = useState('')
  const [room, setRoom] = useState<RoomType>('twin')
  const [hotelCategory, setHotelCategory] = useState<HotelCategory | null>(null)
  const [addons, setAddons] = useState<string[]>([])
  const [pax, setPax] = useState(1)
  const [bookingLocale, setBookingLocale] = useState<'bn' | 'en'>(locale)
  const [serviceName, setServiceName] = useState('')
  const [items, setItems] = useState<CustomItem[]>([emptyItem()])
  const custom = slug === CUSTOM

  const hits = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<{ customers?: CustomerHit[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: mode === 'existing' && lookup.trim().length >= 2,
  })

  const pkg = options.data?.packages.find((p) => p.slug === slug)
  // A package priced by hotel category is booked in one of its categories; basic/3-star until another is picked.
  const categories = gridCategories(pkg?.price_grid)
  const category = categories.length === 0 ? null : hotelCategory && categories.includes(hotelCategory) ? hotelCategory : defaultHotelCategory(pkg?.price_grid)
  const chosenAddons = (options.data?.addons ?? []).filter((addon) => addons.includes(addon.code))
  // A custom service: every item for each traveller, then the service charge and VAT — the API's customQuote exactly.
  const itemsReady = items.length > 0 && items.every((item) => item.title.trim() !== '' && item.price !== null && item.price >= 0)
  const customQuote = (() => {
    if (!custom || !options.data || !itemsReady) return null
    const totals = invoiceTotals({ lines: items.map((item) => ({ quantity: pax, unitPrice: Math.round(item.price ?? 0) })), chargePercent: options.data.config.serviceChargePercent })
    return { perPerson: items.reduce((sum, item) => sum + Math.round(item.price ?? 0), 0), singleSupplement: 0, serviceCharge: totals.charge, total: totals.total }
  })()
  // Recomputed each render; the React Compiler memoises it.
  const packageQuote = (() => {
    if (!pkg || !options.data) return null
    try {
      return quoteBooking({ listPrice: pkg.list_price, pax, room, addons: chosenAddons, config: options.data.config, grid: pkg.price_grid, hotelCategory: category })
    } catch {
      return null
    }
  })()
  const quote = custom ? customQuote : packageQuote

  const create = useMutation({
    mutationFn: () =>
      api.post<Data<BookingDetail>>('admin/bookings', {
        ...(mode === 'existing' && picked ? { customer_id: picked.id } : { customer: { ...customer, email: customer.email || null } }),
        ...(custom
          ? {
              custom: { title: serviceName.trim(), items: items.map((item) => ({ title: item.title.trim(), unit_price: Math.round(item.price ?? 0) })) },
              // A custom service may be booked before its date is known.
              travel_date: travelDate || null,
            }
          : { package_slug: slug, travel_date: travelDate, room, hotel_category: category, addons }),
        pax,
        expected_total: quote?.total ?? 0,
        locale: bookingLocale,
      }),
    onSuccess: (response) => {
      toast(t('newBooking.created', { reference: response.data.reference }))
      navigate(`/bookings/${response.data.id}`)
    },
    onError: (error) => {
      if (error instanceof ApiError && error.code === 'price_changed') void options.refetch()
    },
  })

  if (options.isPending) return <Loading />
  if (options.isError) return <ErrorNotice error={options.error} />

  // The phone number already belongs to a customer: say so beside the customer fields; they can be picked instead.
  const existing = create.error instanceof ApiError && create.error.code === 'customer_exists' ? create.error : null
  const fieldError = (name: string) => (create.error instanceof ApiError ? create.error.field(name) : undefined)
  const canSubmit =
    !!quote &&
    (custom ? serviceName.trim() !== '' : !!travelDate) &&
    (mode === 'existing' ? !!picked : !!customer.name.trim() && !!customer.phone.trim())

  return (
    <>
      <PageHeader title={t('newBooking.title')} subtitle={t('newBooking.subtitle')} actions={<Link to="/bookings" className={buttonClass('outline', 'sm')}>{t('bookings.back')}</Link>} />

      <form
        className="grid-auto-fit-360 grid items-start gap-admin-gap"
        onSubmit={(event) => {
          event.preventDefault()
          if (canSubmit) create.mutate()
        }}
      >
        <div className="flex flex-col gap-admin-gap">
          <Card>
            <CardTitle title="Customer" />
            <Chips label={t('newBooking.customer')} value={mode} onChange={setMode} options={[{ value: 'new', label: t('newBooking.newLead') }, { value: 'existing', label: t('newBooking.existing') }]} />
            {mode === 'new' ? (
              <div className="grid-auto-fit-200 grid gap-3">
                <TextInput label={t('newBooking.name')} value={customer.name} onChange={(name) => setCustomer({ ...customer, name })} error={fieldError('customer.name')} required />
                <TextInput label={t('newBooking.phone')} value={customer.phone} onChange={(phone) => setCustomer({ ...customer, phone })} error={fieldError('customer.phone')} inputMode="tel" placeholder="01711-000000" required />
                <TextInput label={t('newBooking.email')} value={customer.email} onChange={(email) => setCustomer({ ...customer, email })} error={fieldError('customer.email')} type="email" />
                <SelectInput label={t('newBooking.source')} value={customer.source} onChange={(source) => setCustomer({ ...customer, source })} options={options.data.sources.map((value) => ({ value, label: t(`sources.${value}`) }))} />
              </div>
            ) : picked ? (
              <div className="flex items-center justify-between gap-3 rounded-10 border border-app-line p-3">
                <span className="flex flex-col">
                  <span className="font-medium">{picked.name}</span>
                  <span className="font-display text-12 text-app-muted">{digits(picked.phone.replace(/^88/, ''))}</span>
                </span>
                <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setPicked(null)}>
                  {t('common.change')}
                </button>
              </div>
            ) : (
              <div className="flex flex-col gap-2">
                <input type="search" className={controlClass()} value={lookup} onChange={(event) => setLookup(event.target.value)} placeholder={t('newBooking.findCustomer')} aria-label={t('newBooking.findCustomer')} />
                {(hits.data ?? []).map((hit) => (
                  <button key={hit.id} type="button" className={buttonClass('outline', 'sm', 'justify-between')} onClick={() => setPicked(hit)}>
                    <span>{hit.name}</span>
                    <span className="font-display text-12 text-app-muted">{digits(hit.phone.replace(/^88/, ''))}</span>
                  </button>
                ))}
              </div>
            )}
            {existing ? (
              <p role="alert" className="m-0 text-13 text-red">
                {existing.message}
              </p>
            ) : null}
          </Card>

        </div>

        <Card>
          <CardTitle title="Package & price" />
          <SelectInput
            label={t('bookings.package')}
            value={slug}
            onChange={(value) => {
              setSlug(value)
              setTravelDate('')
            }}
            options={[
              { value: '', label: t('common.choose') },
              { value: CUSTOM, label: t('newBooking.customOption') },
              ...options.data.packages.map((p) => ({ value: p.slug, label: p.title_en || p.title_bn || p.slug })),
            ]}
            error={fieldError('package_slug')}
          />
          {custom ? (
            // A custom service (docs/custom-service-bookings.md): its name, then each item at a price per person.
            <div className="flex flex-col gap-3" data-testid="custom-service">
              <TextInput label={t('newBooking.serviceName')} value={serviceName} onChange={setServiceName} error={fieldError('custom.title')} placeholder={t('newBooking.serviceNamePlaceholder')} required />
              {items.map((item, index) => (
                <div key={index} className="flex flex-wrap items-end gap-2 rounded-10 bg-app-surface-2 p-2.5" data-testid="custom-item">
                  <div className="min-w-0 flex-[2_1_200px]">
                    <TextInput
                      label={t('newBooking.itemName', { n: number(index + 1) })}
                      value={item.title}
                      onChange={(title) => setItems((all) => all.map((it, i) => (i === index ? { ...it, title } : it)))}
                      error={fieldError(`custom.items.${index}.title`)}
                    />
                  </div>
                  <div className="min-w-0 flex-[1_1_140px]">
                    <NumberInput
                      label={t('newBooking.itemPrice')}
                      value={item.price}
                      onChange={(price) => setItems((all) => all.map((it, i) => (i === index ? { ...it, price } : it)))}
                      error={fieldError(`custom.items.${index}.unit_price`)}
                    />
                  </div>
                  {items.length > 1 ? (
                    <button type="button" aria-label={t('newBooking.removeItem', { n: number(index + 1) })} className={buttonClass('outline', 'sm', 'mb-0.5')} onClick={() => setItems((all) => all.filter((_, i) => i !== index))}>
                      ×
                    </button>
                  ) : null}
                </div>
              ))}
              {fieldError('custom.items') ? <p className="m-0 text-13 text-red">{fieldError('custom.items')}</p> : null}
              <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => setItems((all) => [...all, emptyItem()])}>
                + {t('newBooking.addItem')}
              </button>
            </div>
          ) : null}
          {custom ? (
            <TextInput label={t('newBooking.travelDateOptional')} type="date" min={todayInDhaka()} value={travelDate} onChange={setTravelDate} error={fieldError('travel_date')} />
          ) : pkg && pkg.departures.length > 0 ? (
            <SelectInput
              label={t('newBooking.departure')}
              value={travelDate}
              onChange={setTravelDate}
              options={[{ value: '', label: t('common.choose') }, ...pkg.departures.map((d) => ({ value: d.date, label: d.seats_left === null ? date(d.date) : t('newBooking.departureSeats', { date: date(d.date), seats: number(d.seats_left) }) }))]}
              error={fieldError('travel_date')}
            />
          ) : (
            <TextInput label={t('newBooking.travelDate')} type="date" min={todayInDhaka()} value={travelDate} onChange={setTravelDate} error={fieldError('travel_date')} />
          )}
          <div className="grid-auto-fit-140 grid gap-3">
            <NumberInput label={t('bookings.travellers')} value={pax} onChange={(value) => setPax(Math.min(Math.max(value ?? 1, 1), options.data.config.maxTravellers))} error={fieldError('pax')} hint={t('newBooking.namesLater')} />
            {category && !custom ? (
              <SelectInput
                label={t('grid.hotelCategory')}
                value={category}
                onChange={(value) => setHotelCategory(value as HotelCategory)}
                options={categories.map((value) => ({ value, label: t(`grid.categories.${value}`) }))}
              />
            ) : null}
            {custom ? null : (
              <SelectInput label={t('bookings.room')} value={room} onChange={(value) => setRoom(value as RoomType)} options={(['twin', 'triple', 'single'] as const).map((value) => ({ value, label: t(`bookings.rooms.${value}`) }))} />
            )}
            <SelectInput label={t('newBooking.messagesIn')} value={bookingLocale} onChange={(value) => setBookingLocale(value as 'bn' | 'en')} options={[{ value: 'bn', label: 'Bangla' }, { value: 'en', label: 'English' }]} />
          </div>
          {options.data.addons.length > 0 && !custom ? (
            <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
              <legend className="mb-1 text-13 text-app-muted">{t('newBooking.addons')}</legend>
              {options.data.addons.map((addon) => (
                <label key={addon.code} className="flex items-center justify-between gap-3 text-14">
                  <span className="flex items-center gap-2">
                    <input type="checkbox" checked={addons.includes(addon.code)} onChange={(event) => setAddons((all) => (event.target.checked ? [...all, addon.code] : all.filter((code) => code !== addon.code)))} />
                    {addon.name_en || addon.name_bn}
                  </span>
                  <span className="font-display text-13 text-app-muted">{bdt(addon.price)}</span>
                </label>
              ))}
            </fieldset>
          ) : null}

          {quote ? (
            <dl className="m-0 flex flex-col gap-1.5 border-t border-app-line pt-3 text-13" data-testid="new-booking-quote">
              <div className="flex justify-between gap-3">
                <dt className="text-app-muted">{custom ? t('newBooking.customPerPerson', { n: number(pax) }) : t('newBooking.perPerson', { n: number(pax) })}</dt>
                <dd className="m-0 font-display font-semibold">{bdt(quote.perPerson)}</dd>
              </div>
              {quote.singleSupplement > 0 ? (
                <div className="flex justify-between gap-3">
                  <dt className="text-app-muted">{t('bookings.line.single')}</dt>
                  <dd className="m-0 font-display font-semibold">{bdt(quote.singleSupplement)}</dd>
                </div>
              ) : null}
              <div className="flex justify-between gap-3">
                <dt className="text-app-muted">{t('bookings.vat')}</dt>
                <dd className="m-0 font-display font-semibold">{bdt(quote.serviceCharge)}</dd>
              </div>
              <div className="flex justify-between gap-3 border-t border-app-line pt-1.5 text-15 font-bold">
                <dt>{t('bookings.total')}</dt>
                <dd className="m-0 font-display" data-testid="new-booking-total">
                  {bdt(quote.total)}
                </dd>
              </div>
            </dl>
          ) : null}

          {create.error && !existing ? <ErrorNotice error={create.error} /> : null}
          <button type="submit" className={buttonClass('cta', 'md', 'w-full')} disabled={!canSubmit || create.isPending}>
            {create.isPending ? t('common.saving') : quote ? t('newBooking.submit', { total: bdt(quote.total) }) : t('newBooking.submitEmpty')}
          </button>
        </Card>
      </form>
    </>
  )
}
