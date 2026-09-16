import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../../components/ui/feedback'
import { Pair, SelectInput, Switch, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../../components/ui/layout'
import { api, ApiError } from '../../../lib/api/client'
import type { Destination } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { slugify, useDestinations } from './api'

type DestinationForm = Pick<Destination, 'slug' | 'name_bn' | 'name_en' | 'country_code' | 'region' | 'visa_on_arrival'>

/** Destinations drive the website's region chips and search select. */
export function DestinationsCard() {
  const { t } = useTranslation()
  const { number } = useFormat()
  const list = useDestinations()
  const [editing, setEditing] = useState<Destination | 'new' | null>(null)

  return (
    <Card>
      <CardTitle title="Destinations" aside={<button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing('new')}>{t('common.add')}</button>} />
      {list.isPending ? <Loading /> : list.isError ? <ErrorNotice error={list.error} /> : (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
          {list.data.data.map((destination) => (
            <li key={destination.id}>
              <button type="button" onClick={() => setEditing(destination)} className="flex w-full cursor-pointer items-center justify-between gap-3 rounded-10 border-0 bg-app-surface-2 px-3 py-2.5 text-left text-14 text-app-text hover:text-blue">
                <span className="flex min-w-0 flex-col">
                  <span className="font-medium">{destination.name_en || destination.name_bn}</span>
                  <span className="font-display text-12 text-app-muted">{destination.slug}</span>
                </span>
                <span className="text-12 whitespace-nowrap text-app-muted">{t('packages.packageCount', { count: destination.packages_count ?? 0, n: number(destination.packages_count ?? 0) })}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {editing ? <DestinationDialog destination={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </Card>
  )
}

function DestinationDialog({ destination, onClose }: { destination: Destination | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<DestinationForm>(
    destination
      ? { slug: destination.slug, name_bn: destination.name_bn, name_en: destination.name_en, country_code: destination.country_code, region: destination.region, visa_on_arrival: destination.visa_on_arrival }
      : { slug: '', name_bn: '', name_en: '', country_code: '', region: 'international', visa_on_arrival: false },
  )
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, country_code: form.country_code?.trim().toUpperCase() || null }
      return destination ? api.put(`admin/destinations/${destination.id}`, body) : api.post('admin/destinations', body)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['destinations'] })
      toast(t('common.saved'))
      onClose()
    },
  })
  const error = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={destination ? t('packages.editDestination') : t('packages.newDestination')}>
      <Pair>
        <TextInput label={t('fields.nameBn')} value={form.name_bn} onChange={(name_bn) => setForm({ ...form, name_bn })} error={error('name_bn')} />
        <TextInput label={t('fields.nameEn')} value={form.name_en} onChange={(name_en) => setForm((current) => ({ ...current, name_en, slug: destination || current.slug !== slugify(current.name_en) ? current.slug : slugify(name_en) }))} error={error('name_en')} />
      </Pair>
      <Pair>
        <TextInput label={t('fields.slug')} value={form.slug} onChange={(slug) => setForm({ ...form, slug })} error={error('slug')} hint={t('fields.slugHint')} />
        <TextInput label={t('packages.countryCode')} value={form.country_code} maxLength={2} onChange={(country_code) => setForm({ ...form, country_code })} error={error('country_code')} hint={t('packages.countryCodeHint')} />
      </Pair>
      <SelectInput label={t('packages.region')} value={form.region} onChange={(region) => setForm({ ...form, region: region as Destination['region'] })} options={[{ value: 'international', label: t('packages.international') }, { value: 'domestic', label: t('packages.domestic') }]} />
      <Switch label={t('packages.visaOnArrival')} hint={t('packages.visaOnArrivalHint')} checked={form.visa_on_arrival} onChange={(visa_on_arrival) => setForm({ ...form, visa_on_arrival })} />
      {save.error instanceof ApiError && save.error.status === 422 ? null : <ErrorNotice error={save.error} />}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>{t('common.cancel')}</button>
        <button type="button" className={buttonClass('primary')} onClick={() => save.mutate()} aria-disabled={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</button>
      </div>
    </Dialog>
  )
}
