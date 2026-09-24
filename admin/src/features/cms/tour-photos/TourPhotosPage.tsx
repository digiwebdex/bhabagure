import { useTranslation } from 'react-i18next'

import { Pair, SelectInput, TextInput } from '../../../components/ui/fields'
import type { Media, TourPhoto } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'
import { MediaThumb } from '../media/media'
import { usePackages } from '../packages/api'

type PhotoForm = Pick<TourPhoto, 'caption_bn' | 'caption_en' | 'media_id' | 'tour_package_id'> & { trip_month: string; image: Media | null }

/**
 * Group tour photos (docs/group-tour-gallery.md): travellers on their trips, in the slideshow on the home page in this
 * order. A photo is needed before one can be published; the tour link shows only while that package is on the website.
 */
export function TourPhotosPage() {
  const { t } = useTranslation()
  const { month } = useFormat()
  const packages = usePackages('all', '', '')
  const packageOptions = [
    { value: '', label: t('tourPhotos.noPackage') },
    ...(packages.data?.data ?? []).map((pkg) => ({ value: String(pkg.id), label: pkg.status === 'published' ? pkg.title_en : t('tourPhotos.notOnWebsite', { title: pkg.title_en }) })),
  ]

  return (
    <PublishedList<TourPhoto, PhotoForm>
      resource="tour-photos"
      title={t('tourPhotos.title')}
      subtitle={t('tourPhotos.subtitle')}
      newLabel={t('tourPhotos.new')}
      emptyTitle={t('tourPhotos.empty')}
      emptyNote={t('tourPhotos.emptyNote')}
      note={<p className="m-0 text-13 text-app-muted">{t('tourPhotos.note')}</p>}
      row={(photo) => ({
        label: photo.caption_en,
        content: (
          <>
            <MediaThumb media={photo.image} className="aspect-4/3 w-20" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="truncate text-14 font-medium">{photo.caption_en}</span>
              <span className="truncate text-12 text-app-muted">
                {[photo.trip_month ? month(photo.trip_month) : null, photo.package_title ?? t('tourPhotos.noPackage')].filter(Boolean).join(' · ')}
              </span>
            </span>
          </>
        ),
      })}
      toForm={(photo) => ({
        caption_bn: photo?.caption_bn ?? '',
        caption_en: photo?.caption_en ?? '',
        media_id: photo?.media_id ?? null,
        trip_month: photo?.trip_month ?? '',
        tour_package_id: photo?.tour_package_id ?? null,
        image: photo?.image ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextInput label={t('tourPhotos.captionBn')} value={form.caption_bn} onChange={(value) => set('caption_bn', value)} error={error('caption_bn')} placeholder="মুস্তাং, নেপাল" hint={t('tourPhotos.captionHint')} />
            <TextInput label={t('tourPhotos.captionEn')} value={form.caption_en} onChange={(value) => set('caption_en', value)} error={error('caption_en')} placeholder="Mustang, Nepal" />
          </Pair>
          <Pair>
            <TextInput label={t('tourPhotos.month')} type="month" value={form.trip_month} onChange={(value) => set('trip_month', value)} error={error('trip_month')} hint={t('tourPhotos.monthHint')} />
            <SelectInput
              label={t('tourPhotos.package')}
              value={form.tour_package_id === null ? '' : String(form.tour_package_id)}
              onChange={(value) => set('tour_package_id', value === '' ? null : Number(value))}
              options={packageOptions}
              error={error('tour_package_id')}
              hint={t('tourPhotos.packageHint')}
            />
          </Pair>
          <ImageField
            label={t('tourPhotos.photo')}
            media={form.image}
            error={error('media_id')}
            onChange={(media) => {
              set('image', media)
              set('media_id', media?.id ?? null)
            }}
          />
        </>
      )}
    />
  )
}
