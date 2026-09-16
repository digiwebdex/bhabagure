import { gridCategories } from '@bhabaghure/pricing'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useBlocker, useNavigate, useParams } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, UnsavedChangesPrompt, useConfirm, useToast } from '../../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, Switch, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader, StatusBadge } from '../../../components/ui/layout'
import { ApiError } from '../../../lib/api/client'
import type { TourPackage } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { emptyPackage, packageActions, publishChecklist, slugify, toForm, useDestinations, usePackage, usePackageMutation, useTags, type PackageForm } from './api'
import { InclusionsEditor, ItineraryEditor, SeoFields, TagInput } from './ContentLists'
import { DeparturesPanel } from './DeparturesPanel'
import { PackagePhotos } from './PackagePhotos'
import { PriceGridField } from './PriceGridField'

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')

export function PackageEditorPage() {
  const { id } = useParams()
  const packageId = id ? Number(id) : null
  const query = usePackage(packageId)
  const { t } = useTranslation()

  if (packageId !== null && Number.isNaN(packageId)) return <EmptyState title={t('errors.notFoundTitle')} />
  if (packageId !== null && query.isPending) return <Loading />
  if (packageId !== null && query.isError) return <ErrorNotice error={query.error} />

  // Keyed by id and update time, so a save (or opening another package) starts from the server's copy.
  const pkg = query.data?.data ?? null
  return <Editor key={pkg ? `${pkg.id}-${pkg.updated_at}` : 'new'} pkg={pkg} />
}

function Editor({ pkg }: { pkg: TourPackage | null }) {
  const { t } = useTranslation()
  const { bdt, number, date, locale } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const destinations = useDestinations()
  const tags = useTags()
  const { confirm, element: confirmDialog } = useConfirm()

  const initial = pkg ? toForm(pkg) : emptyPackage()
  const [form, setForm] = useState<PackageForm>(initial)
  const [slugTouched, setSlugTouched] = useState(!!pkg)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)
  // Priced by hotel category: the one price and the sale price come from the grid (Phase 8 §4.D).
  const hasGrid = gridCategories(form.price_grid).length > 0

  const save = usePackageMutation((values: PackageForm) => (pkg ? packageActions.update(pkg.id, values) : packageActions.create(values)))
  const transition = usePackageMutation((action: 'publish' | 'unpublish' | 'archive') => packageActions.transition(pkg!.id, action))
  const remove = usePackageMutation(() => packageActions.remove(pkg!.id))

  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && !save.isPending && currentLocation.pathname !== nextLocation.pathname)

  const set = <K extends keyof PackageForm>(key: K, value: PackageForm[K]) => setForm((current) => ({ ...current, [key]: value }))
  const error = (path: string) => (save.error instanceof ApiError ? save.error.field(path) : undefined)

  const onSave = () =>
    save.mutate(form, {
      onSuccess: (result) => {
        toast(t('common.saved'))
        if (!pkg) navigate(`/packages/${result.data.id}`, { replace: true })
      },
    })

  const onTransition = async (action: 'publish' | 'unpublish' | 'archive') => {
    if (transition.isPending) return
    if (dirty) return toast(t('packages.saveFirst'), 'error')
    if (action === 'archive' && !(await confirm(t('packages.confirmArchive')))) return
    transition.mutate(action, { onSuccess: () => toast(t(`packages.done.${action}`)) })
  }

  const onDelete = async () => {
    if (!(await confirm(t('packages.confirmDelete')))) return
    remove.mutate(undefined, {
      onSuccess: () => {
        toast(t('common.deleted'))
        navigate('/packages', { replace: true })
      },
    })
  }

  const checklist = publishChecklist(form, pkg?.images.length ?? 0)
  const websiteUrl = `${SITE_URL}${locale === 'en' ? '/en' : ''}/packages/${form.slug || '…'}`
  const saveError = save.error instanceof ApiError && save.error.status === 422 && save.error.problems.length === 0 ? null : save.error
  const title = form.title_en || form.title_bn || t('packages.untitled')

  return (
    <>
      <PageHeader
        title={pkg ? title : t('packages.new')}
        subtitle={pkg ? `${pkg.code} · ${t('packages.lastSaved', { date: pkg.updated_at ? date(pkg.updated_at) : '—' })}` : t('packages.newSubtitle')}
        actions={
          <>
            <Link to="/packages" className={buttonClass('outline')}>
              {t('common.back')}
            </Link>
            <button type="button" className={buttonClass('cta')} onClick={onSave} aria-disabled={save.isPending}>
              {save.isPending ? t('common.saving') : dirty || !pkg ? t('common.save') : t('common.saved')}
            </button>
          </>
        }
      />
      {saveError ? <ErrorNotice error={saveError} /> : save.error ? <ErrorNotice error={new ApiError(422, { message: t('errors.fixFields') })} /> : null}

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <div className="flex min-w-0 flex-col gap-4.5">
          <Card>
            <CardTitle title="Basics" />
            <Pair>
              <TextInput label={t('fields.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} />
              <TextInput
                label={t('fields.titleEn')}
                value={form.title_en}
                onChange={(value) => setForm((current) => ({ ...current, title_en: value, slug: slugTouched ? current.slug : slugify(value) }))}
                error={error('title_en')}
              />
            </Pair>
            <Pair>
              <TextInput label={t('packages.code')} value={form.code} onChange={(value) => set('code', value)} error={error('code')} hint={t('packages.codeHint')} />
              <TextInput
                label={t('fields.slug')}
                value={form.slug}
                onChange={(value) => {
                  setSlugTouched(true)
                  set('slug', value)
                }}
                error={error('slug')}
                hint={<span className="break-all">{websiteUrl}</span>}
              />
            </Pair>
            <Pair>
              <SelectInput
                label={t('packages.destination')}
                value={String(form.destination_id || '')}
                onChange={(value) => set('destination_id', Number(value))}
                error={error('destination_id')}
                options={[{ value: '', label: t('common.choose') }, ...(destinations.data?.data ?? []).map((d) => ({ value: String(d.id), label: d.name_en || d.name_bn }))]}
              />
              <SelectInput
                label={t('packages.includesAirfare')}
                value={form.includes_airfare === null ? 'unknown' : form.includes_airfare ? 'yes' : 'no'}
                onChange={(value) => set('includes_airfare', value === 'unknown' ? null : value === 'yes')}
                options={[
                  { value: 'yes', label: t('packages.airfareYes') },
                  { value: 'no', label: t('packages.airfareNo') },
                  { value: 'unknown', label: t('packages.airfareAsk') },
                ]}
              />
            </Pair>
            <Pair>
              <NumberInput label={t('packages.days')} value={form.duration_days} onChange={(value) => set('duration_days', value ?? 0)} error={error('duration_days')} />
              <NumberInput label={t('packages.nights')} value={form.duration_nights} onChange={(value) => set('duration_nights', value)} error={error('duration_nights')} />
            </Pair>
            <Pair>
              <NumberInput disabled={hasGrid} label={t('packages.regularPrice')} value={form.regular_price} onChange={(value) => set('regular_price', value ?? 0)} error={error('regular_price')} preview={(value) => t('packages.perPerson', { amount: bdt(value) })} />
              <NumberInput disabled={hasGrid} label={t('packages.salePrice')} value={hasGrid ? null : form.sale_price} onChange={(value) => set('sale_price', value)} error={error('sale_price')} preview={(value) => t('packages.perPerson', { amount: bdt(value) })} hint={hasGrid ? t('grid.pricesFromGrid') : form.sale_price === null ? t('packages.salePriceHint') : undefined} />
            </Pair>
            <PriceGridField value={form.price_grid} onChange={(grid) => set('price_grid', grid)} error={error} />
            <Pair>
              <SelectInput
                label={t('packages.departureMode')}
                value={form.departure_mode}
                onChange={(value) => set('departure_mode', value as PackageForm['departure_mode'])}
                options={(['regular', 'any_date', 'on_request'] as const).map((value) => ({ value, label: t(`packages.departureModes.${value}`) }))}
              />
              <SelectInput
                label={t('packages.groupMode')}
                value={form.group_mode}
                onChange={(value) => set('group_mode', value as PackageForm['group_mode'])}
                options={(['group', 'any'] as const).map((value) => ({ value, label: t(`packages.groupModes.${value}`) }))}
              />
            </Pair>
            <Pair>
              <NumberInput label={t('packages.minPax')} value={form.min_pax} onChange={(value) => set('min_pax', value)} error={error('min_pax')} hint={t('packages.minPaxHint')} />
              <div className="flex items-end pb-2">
                <Switch label={t('packages.featured')} checked={form.is_featured} onChange={(value) => set('is_featured', value)} />
              </div>
            </Pair>
            <Pair>
              <TextArea label={t('packages.summaryBn')} value={form.summary_bn} onChange={(value) => set('summary_bn', value)} error={error('summary_bn')} hint={t('packages.summaryHint')} />
              <TextArea label={t('packages.summaryEn')} value={form.summary_en} onChange={(value) => set('summary_en', value)} error={error('summary_en')} />
            </Pair>
          </Card>

          <Card>
            <CardTitle title="Itinerary · day by day" aside={<span className="text-12 text-app-muted">{t('packages.dayCount', { count: form.itinerary.length, n: number(form.itinerary.length) })}</span>} />
            <ItineraryEditor days={form.itinerary} onChange={(days) => set('itinerary', days)} error={error} />
          </Card>

          <Card>
            <CardTitle title="Included" />
            <InclusionsEditor path="includes" items={form.includes} onChange={(items) => set('includes', items)} error={error} addLabel={t('packages.addIncluded')} />
            <CardTitle title="Not included" as="h3" />
            <InclusionsEditor path="excludes" items={form.excludes} onChange={(items) => set('excludes', items)} error={error} addLabel={t('packages.addExcluded')} />
          </Card>

          <Card>
            <CardTitle title="Tags" />
            <Pair>
              <TagInput label={t('packages.activities')} values={form.activities} onChange={(values) => set('activities', values)} suggestions={(tags.data?.data ?? []).filter((tag) => tag.type === 'activity').map((tag) => tag.name_en)} />
              <TagInput label={t('packages.tripTypes')} values={form.trip_types} onChange={(values) => set('trip_types', values)} suggestions={(tags.data?.data ?? []).filter((tag) => tag.type === 'trip_type').map((tag) => tag.name_en)} />
            </Pair>
          </Card>

          <Card>
            <CardTitle title="SEO · search results and sharing" />
            <SeoFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} url={websiteUrl} fallbackTitle={title} />
          </Card>
        </div>

        <aside className="flex min-w-0 flex-col gap-4.5 lg:sticky lg:top-5">
          <Card>
            <CardTitle title="Publishing" aside={pkg ? <StatusBadge status={pkg.status} /> : <StatusBadge status="draft" />} />
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
              {checklist.map((item) => (
                <li key={item.key} className="flex items-center gap-2 text-13">
                  <span aria-hidden className={`flex size-4.5 shrink-0 items-center justify-center rounded-5 text-11 text-white ${item.done ? 'bg-green' : 'bg-app-line'}`}>
                    {item.done ? '✓' : ''}
                  </span>
                  <span className={item.done ? '' : 'text-app-muted'}>{t(`packages.checklist.${item.key}`)}</span>
                  <span className="sr-only">{item.done ? t('common.done') : t('common.missing')}</span>
                </li>
              ))}
            </ul>
            {!pkg ? <p className="m-0 text-12 text-app-muted">{t('packages.saveToContinue')}</p> : null}
            <ErrorNotice error={transition.error ?? remove.error} />
            {pkg ? (
              <div className="flex flex-wrap gap-2">
                {pkg.status !== 'published' ? (
                  <button type="button" className={buttonClass('success')} onClick={() => void onTransition('publish')} aria-disabled={transition.isPending}>
                    {t('packages.publish')}
                  </button>
                ) : (
                  <button type="button" className={buttonClass('outline')} onClick={() => void onTransition('unpublish')} aria-disabled={transition.isPending}>
                    {t('packages.unpublish')}
                  </button>
                )}
                {pkg.status !== 'archived' ? (
                  <button type="button" className={buttonClass('outline')} onClick={() => void onTransition('archive')}>
                    {t('packages.archive')}
                  </button>
                ) : null}
                {pkg.status === 'published' && SITE_URL ? (
                  <a href={websiteUrl} target="_blank" rel="noreferrer" className={buttonClass('ghost')}>
                    {t('packages.viewOnWebsite')}
                  </a>
                ) : null}
              </div>
            ) : null}
            {pkg && pkg.status === 'published' ? <p className="m-0 text-12 leading-1.55 text-app-muted">{t('packages.liveNote')}</p> : null}
          </Card>

          <Card>
            <CardTitle title="Photos" />
            {pkg ? <PackagePhotos packageId={pkg.id} images={pkg.images} /> : <p className="m-0 text-13 text-app-muted">{t('packages.saveToContinue')}</p>}
          </Card>

          <Card>
            <CardTitle title="Group departures" />
            {pkg ? <DeparturesPanel packageId={pkg.id} durationDays={form.duration_days} /> : <p className="m-0 text-13 text-app-muted">{t('packages.saveToContinue')}</p>}
          </Card>

          {pkg ? (
            <button type="button" className={buttonClass('danger', 'md', 'self-start')} onClick={() => void onDelete()}>
              {t('packages.delete')}
            </button>
          ) : null}
        </aside>
      </div>

      {blocker.state === 'blocked' ? (
        <UnsavedChangesPrompt onStay={() => blocker.reset()} onLeave={() => blocker.proceed()} />
      ) : null}
      {confirmDialog}
    </>
  )
}
