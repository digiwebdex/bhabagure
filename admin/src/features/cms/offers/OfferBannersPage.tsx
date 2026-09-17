import { useTranslation } from 'react-i18next'

import { Pair, TextInput } from '../../../components/ui/fields'
import type { Media, OfferBanner } from '../../../lib/api/types'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'
import { MediaThumb } from '../media/media'

type BannerForm = Pick<OfferBanner, 'title_bn' | 'title_en' | 'media_id' | 'link_url'> & { image: Media | null }

/**
 * Offer banners (docs/offer-banners.md): the pictures that slide under the hero video on the home page, in this order.
 * A banner is its picture, so one is needed before it can be published.
 */
export function OfferBannersPage() {
  const { t } = useTranslation()

  return (
    <PublishedList<OfferBanner, BannerForm>
      resource="offer-banners"
      title={t('offers.title')}
      subtitle={t('offers.subtitle')}
      newLabel={t('offers.new')}
      emptyTitle={t('offers.empty')}
      emptyNote={t('offers.emptyNote')}
      row={(banner) => ({
        label: banner.title_en,
        content: (
          <>
            <MediaThumb media={banner.image} className="aspect-3/1 w-28" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="truncate text-14 font-medium">{banner.title_en}</span>
              <span className="truncate text-12 text-app-muted">{banner.link_url ?? t('offers.noLink')}</span>
            </span>
          </>
        ),
      })}
      toForm={(banner) => ({
        title_bn: banner?.title_bn ?? '',
        title_en: banner?.title_en ?? '',
        media_id: banner?.media_id ?? null,
        link_url: banner?.link_url ?? '',
        image: banner?.image ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextInput label={t('offers.titleBn')} value={form.title_bn} onChange={(value) => set('title_bn', value)} error={error('title_bn')} hint={t('offers.titleHint')} />
            <TextInput label={t('offers.titleEn')} value={form.title_en} onChange={(value) => set('title_en', value)} error={error('title_en')} />
          </Pair>
          <TextInput label={t('offers.link')} value={form.link_url} onChange={(value) => set('link_url', value)} error={error('link_url')} placeholder="/packages/…" hint={t('offers.linkHint')} />
          <ImageField
            label={t('offers.image')}
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
