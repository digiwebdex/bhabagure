import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { Link, useBlocker, useNavigate, useParams } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, UnsavedChangesPrompt, useConfirm, useToast } from '../../../components/ui/feedback'
import { Pair, SelectInput, Switch, TextArea, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader, StatusBadge } from '../../../components/ui/layout'
import { ApiError } from '../../../lib/api/client'
import type { Media } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { ImageField } from '../lists/ImageField'
import { MediaPicker, MediaThumb } from '../media/media'
import { emptyTourPage, toTourPageForm, tourPageActions, useTourPage, useTourPageMutation, useTourPageOptions, type TourPage, type TourPageForm } from './api'

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')
const PHOTOS_PER_DAY = 3

export function TourPageEditorPage() {
  const { id } = useParams()
  const pageId = id ? Number(id) : null
  const query = useTourPage(pageId)
  const { t } = useTranslation()

  if (pageId !== null && Number.isNaN(pageId)) return <EmptyState title={t('errors.notFoundTitle')} />
  if (pageId !== null && query.isPending) return <Loading />
  if (pageId !== null && query.isError) return <ErrorNotice error={query.error} />

  const page = query.data?.data ?? null
  return <Editor key={page ? `${page.id}-${page.updated_at}` : 'new'} page={page} />
}

/**
 * A landing page's editor (docs/tour-landing-pages.md). What the page shows of the tour itself — title, prices, dates,
 * each day's text, inclusions — is the package's and is only shown here; this edits the page's own presentation.
 */
function Editor({ page }: { page: TourPage | null }) {
  const { t } = useTranslation()
  const { date, dateTime, locale } = useFormat()
  const toast = useToast()
  const navigate = useNavigate()
  const options = useTourPageOptions()
  const { confirm, element: confirmDialog } = useConfirm()

  const initial = page ? toTourPageForm(page) : emptyTourPage()
  const [form, setForm] = useState<TourPageForm>(initial)
  const [pickingDay, setPickingDay] = useState<number | null>(null)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)

  const save = useTourPageMutation((values: TourPageForm) => (page ? tourPageActions.update(page.id, values) : tourPageActions.create(values)))
  const transition = useTourPageMutation((action: 'publish' | 'unpublish') => tourPageActions.transition(page!.id, action))
  const remove = useTourPageMutation(() => tourPageActions.remove(page!.id))
  // Not a change to the page: a plain mutation, nothing refetched.
  const preview = useMutation({ mutationFn: () => tourPageActions.previewLink(page!.id) })
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && !save.isPending && currentLocation.pathname !== nextLocation.pathname)

  const set = <K extends keyof TourPageForm>(key: K, value: TourPageForm[K]) => setForm((current) => ({ ...current, [key]: value }))
  const error = (path: string) => (save.error instanceof ApiError ? save.error.field(path) : undefined)
  const pkg = page?.package ?? null
  const websiteUrl = `${SITE_URL}${locale === 'en' ? '/en' : ''}/tours/${form.slug || '…'}`
  const today = new Date().toISOString().slice(0, 10)

  const onSave = () =>
    save.mutate(form, {
      onSuccess: (result) => {
        toast(t('common.saved'))
        if (!page) navigate(`/tour-pages/${result.data.id}`, { replace: true })
      },
    })

  const onTransition = (action: 'publish' | 'unpublish') => {
    if (transition.isPending) return
    if (dirty) return toast(t('tourPages.saveFirst'), 'error')
    transition.mutate(action, { onSuccess: () => toast(t(`tourPages.done.${action}`)) })
  }

  const onDelete = async () => {
    if (!(await confirm(t('tourPages.confirmDelete')))) return
    remove.mutate(undefined, {
      onSuccess: () => {
        toast(t('common.deleted'))
        navigate('/tour-pages', { replace: true })
      },
    })
  }

  const addDayPhoto = (day: number, media: Media) =>
    setForm((current) => ({ ...current, day_photos: [...current.day_photos, { day_number: day, media }] }))
  const removeDayPhoto = (index: number) => setForm((current) => ({ ...current, day_photos: current.day_photos.filter((_, i) => i !== index) }))

  // A new page: the group tours without one. The package can't change afterwards; make another page instead.
  const choices = (options.data?.data ?? []).filter((option) => option.landing_page_id === null || option.id === form.tour_package_id)
  const upcoming = (pkg?.departures ?? []).filter((d) => d.status === 'scheduled' && d.departs_on >= today)
  const dayNumbers = new Set((pkg?.days ?? []).map((d) => d.day_number))
  const orphans = form.day_photos.filter((p) => !dayNumbers.has(p.day_number))
  const saveError = save.error instanceof ApiError && save.error.status === 422 && save.error.problems.length === 0 ? null : save.error

  return (
    <>
      <PageHeader
        title={page ? (pkg?.title_en ?? page.slug) : t('tourPages.new')}
        subtitle={page ? `/tours/${page.slug}${page.updated_at ? ` · ${t('packages.lastSaved', { date: date(page.updated_at) })}` : ''}` : t('tourPages.newSubtitle')}
        actions={
          <>
            <Link to="/tour-pages" className={buttonClass('outline')}>
              {t('common.back')}
            </Link>
            <button type="button" className={buttonClass('cta')} onClick={onSave} aria-disabled={save.isPending}>
              {save.isPending ? t('common.saving') : dirty || !page ? t('common.save') : t('common.saved')}
            </button>
          </>
        }
      />
      {saveError ? <ErrorNotice error={saveError} /> : save.error ? <ErrorNotice error={new ApiError(422, { message: t('errors.fixFields') })} /> : null}

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <div className="flex min-w-0 flex-col gap-4.5">
          <Card>
            <CardTitle title={t('tourPages.basics')} />
            <SelectInput
              label={t('tourPages.package')}
              value={form.tour_package_id ? String(form.tour_package_id) : ''}
              onChange={(value) => {
                const chosen = choices.find((option) => option.id === Number(value))
                setForm((current) => ({ ...current, tour_package_id: Number(value), slug: current.slug || (chosen?.slug ?? '') }))
              }}
              options={[{ value: '', label: t('tourPages.choosePackage') }, ...choices.map((option) => ({ value: String(option.id), label: option.status === 'published' ? option.title_en : t('tourPages.notOnWebsite', { title: option.title_en }) }))]}
              error={error('tour_package_id')}
              hint={t('tourPages.packageHint')}
              disabled={!!page}
            />
            <TextInput label={t('tourPages.slug')} value={form.slug} onChange={(value) => set('slug', value)} error={error('slug')} hint={<span className="break-all">{websiteUrl}</span>} />
            {pkg ? (
              <Link to={`/packages/${pkg.id}`} className="text-13">
                {t('tourPages.editPackage')} →
              </Link>
            ) : null}
          </Card>

          {page ? (
            <>
              <Card>
                <CardTitle title={t('tourPages.banner')} />
                <ImageField label={t('tourPages.bannerPhoto')} media={form.hero} onChange={(media) => set('hero', media)} error={error('hero_media_id')} />
                <p className="m-0 text-12 text-app-muted">{t('tourPages.bannerHint')}</p>
              </Card>

              <Card>
                <CardTitle title={t('tourPages.words')} />
                <p className="m-0 text-12 leading-1.55 text-app-muted">{t('tourPages.wordsHint')}</p>
                <Pair>
                  <TextInput label={t('tourPages.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} placeholder={pkg?.title_bn ?? ''} />
                  <TextInput label={t('tourPages.titleEn')} value={form.title_en} onChange={(value) => set('title_en', value)} error={error('title_en')} placeholder={pkg?.title_en ?? ''} />
                </Pair>
                <TextArea label={t('tourPages.introBn')} value={form.intro_bn} onChange={(value) => set('intro_bn', value)} error={error('intro_bn')} rows={5} placeholder={pkg?.overview_bn ?? t('tourPages.noOverview')} />
                <TextArea label={t('tourPages.introEn')} value={form.intro_en} onChange={(value) => set('intro_en', value)} error={error('intro_en')} rows={5} placeholder={pkg?.overview_en ?? t('tourPages.noOverview')} />
              </Card>

              <Card>
                <CardTitle title={t('tourPages.days')} />
                <p className="m-0 text-12 leading-1.55 text-app-muted">{t('tourPages.daysHint')}</p>
                {error('day_photos') ? <span role="alert" className="text-12 font-semibold text-red">{error('day_photos')}</span> : null}
                {(pkg?.days ?? []).length === 0 ? <p className="m-0 text-13 text-app-muted">{t('tourPages.noDays')}</p> : null}
                <ol className="m-0 flex list-none flex-col gap-3 p-0">
                  {(pkg?.days ?? []).map((day) => {
                    const photos = form.day_photos.map((p, index) => ({ ...p, index })).filter((p) => p.day_number === day.day_number)
                    return (
                      <li key={day.day_number} className="flex flex-col gap-2 rounded-13 border border-app-line p-3" data-testid="tour-page-day">
                        <span className="text-14 font-semibold">
                          {t('tourPages.dayN', { n: day.day_number })} · {day.title_en || day.title_bn}
                        </span>
                        <div className="flex flex-wrap items-center gap-2.5">
                          {photos.map((photo) => (
                            <span key={`${photo.media.id}-${photo.index}`} className="relative">
                              <MediaThumb media={photo.media} className="aspect-4/3 w-28 rounded-9" />
                              <button
                                type="button"
                                aria-label={t('common.remove')}
                                onClick={() => removeDayPhoto(photo.index)}
                                className="absolute -top-2 -right-2 size-6 cursor-pointer rounded-full border border-app-line bg-app-surface text-12 text-red"
                              >
                                ×
                              </button>
                            </span>
                          ))}
                          {photos.length < PHOTOS_PER_DAY ? (
                            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setPickingDay(day.day_number)}>
                              + {t('tourPages.addPhoto')}
                            </button>
                          ) : null}
                        </div>
                      </li>
                    )
                  })}
                </ol>
                {orphans.length > 0 ? (
                  <p role="alert" className="m-0 text-12 font-semibold text-amber">
                    {t('tourPages.orphans', { days: [...new Set(orphans.map((p) => p.day_number))].join(', ') })}
                  </p>
                ) : null}
              </Card>

              <Card>
                <CardTitle title={t('tourPages.memories')} />
                <p className="m-0 text-13 leading-1.55 text-app-muted">{t('tourPages.memoriesHint')}</p>
                <Link to="/tour-photos" className="text-13">
                  {t('tourPages.editGallery')} →
                </Link>
              </Card>
            </>
          ) : (
            <p className="m-0 text-13 text-app-muted">{t('tourPages.saveToContinue')}</p>
          )}
        </div>

        <aside className="flex min-w-0 flex-col gap-4.5 lg:sticky lg:top-5">
          {page ? (
            <Card>
              <CardTitle title={t('tourPages.publishing')} aside={<StatusBadge status={page.status} />} />
              {pkg && pkg.status !== 'published' ? <p className="m-0 text-12 font-semibold text-amber">{t('tourPages.packageNotLiveHint')}</p> : null}
              <ErrorNotice error={transition.error ?? remove.error} />
              <div className="flex flex-wrap gap-2">
                {page.status !== 'published' ? (
                  <button type="button" className={buttonClass('success')} onClick={() => onTransition('publish')} aria-disabled={transition.isPending}>
                    {t('tourPages.publish')}
                  </button>
                ) : (
                  <button type="button" className={buttonClass('outline')} onClick={() => onTransition('unpublish')} aria-disabled={transition.isPending}>
                    {t('tourPages.unpublish')}
                  </button>
                )}
                {page.status === 'published' && SITE_URL ? (
                  <a href={websiteUrl} target="_blank" rel="noreferrer" className={buttonClass('ghost')}>
                    {t('packages.viewOnWebsite')}
                  </a>
                ) : null}
                <button
                  type="button"
                  className={buttonClass('outline')}
                  onClick={() => (dirty ? toast(t('tourPages.saveFirst'), 'error') : preview.mutate())}
                  aria-disabled={preview.isPending}
                >
                  {preview.isPending ? t('tourPages.previewMaking') : t('tourPages.preview')}
                </button>
              </div>
              <ErrorNotice error={preview.error} />
              {preview.data ? (
                <div className="flex flex-col gap-1 rounded-11 bg-app-surface-2 p-3 text-13" data-testid="tour-preview-links">
                  <span className="text-app-muted">{t('tourPages.previewReady', { time: dateTime(preview.data.data.expires_at) })}</span>
                  <span className="flex flex-wrap gap-3">
                    <a href={preview.data.data.url_bn} target="_blank" rel="noopener noreferrer">
                      {t('tourPages.previewBangla')} ↗
                    </a>
                    <a href={preview.data.data.url_en} target="_blank" rel="noopener noreferrer">
                      {t('tourPages.previewEnglish')} ↗
                    </a>
                  </span>
                </div>
              ) : null}
            </Card>
          ) : null}

          {page ? (
            <Card>
              <CardTitle title={t('tourPages.countdown')} />
              <SelectInput
                label={t('tourPages.countdownTo')}
                value={form.countdown_departure_id ? String(form.countdown_departure_id) : ''}
                onChange={(value) => set('countdown_departure_id', value ? Number(value) : null)}
                options={[{ value: '', label: t('tourPages.countdownAuto') }, ...upcoming.map((d) => ({ value: String(d.id), label: d.returns_on ? `${date(d.departs_on)} – ${date(d.returns_on)}` : date(d.departs_on) }))]}
                error={error('countdown_departure_id')}
                hint={upcoming.length === 0 ? t('tourPages.noUpcoming') : t('tourPages.countdownHint')}
              />
            </Card>
          ) : null}

          {page ? (
            <Card>
              <CardTitle title={t('tourPages.home')} />
              <Switch label={t('tourPages.promote')} checked={form.promote_on_home} onChange={(value) => set('promote_on_home', value)} hint={t('tourPages.promoteHint')} />
            </Card>
          ) : null}

          {page ? (
            <button type="button" className={buttonClass('danger', 'md', 'self-start')} onClick={() => void onDelete()}>
              {t('tourPages.delete')}
            </button>
          ) : null}
        </aside>
      </div>

      <MediaPicker
        open={pickingDay !== null}
        onClose={() => setPickingDay(null)}
        onPick={(media) => {
          if (pickingDay !== null) addDayPhoto(pickingDay, media)
          setPickingDay(null)
        }}
        title={pickingDay !== null ? t('tourPages.dayN', { n: pickingDay }) : undefined}
      />
      {blocker.state === 'blocked' ? <UnsavedChangesPrompt onStay={() => blocker.reset()} onLeave={() => blocker.proceed()} /> : null}
      {confirmDialog}
    </>
  )
}
