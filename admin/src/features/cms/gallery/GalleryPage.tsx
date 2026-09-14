import { useTranslation } from 'react-i18next'

import { NumberInput, Pair, SelectInput, TextInput } from '../../../components/ui/fields'
import { Badge } from '../../../components/ui/layout'
import type { GalleryItem, Media } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'
import { MediaThumb } from '../media/media'

type GalleryForm = Pick<GalleryItem, 'kind' | 'url' | 'caption_bn' | 'caption_en' | 'view_count' | 'media_id'> & { thumbnail: Media | null }

export function GalleryPage() {
  const { t } = useTranslation()
  const { number, locale } = useFormat()

  return (
    <PublishedList<GalleryItem, GalleryForm>
      resource="gallery"
      title={t('gallery.title')}
      subtitle={t('gallery.subtitle')}
      newLabel={t('gallery.new')}
      emptyTitle={t('gallery.empty')}
      emptyNote={t('gallery.emptyNote')}
      row={(item) => ({
        label: item.caption_en ?? item.url,
        content: (
          <>
            <MediaThumb media={item.thumbnail} className="aspect-4/3 w-20" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="flex flex-wrap items-center gap-2">
                <Badge tone={item.kind === 'reel' ? 'blue' : 'slate'}>{t(`gallery.kinds.${item.kind}`)}</Badge>
                {item.view_count !== null ? <span className="text-12 text-app-muted">{t('gallery.views', { n: number(item.view_count) })}</span> : null}
              </span>
              <span className="truncate text-13">{(locale === 'bn' ? item.caption_bn : item.caption_en) ?? item.url}</span>
            </span>
          </>
        ),
      })}
      toForm={(item) => ({
        kind: item?.kind ?? 'reel',
        url: item?.url ?? '',
        caption_bn: item?.caption_bn ?? '',
        caption_en: item?.caption_en ?? '',
        view_count: item?.view_count ?? null,
        media_id: item?.media_id ?? null,
        thumbnail: item?.thumbnail ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <SelectInput label={t('gallery.kind')} value={form.kind} onChange={(value) => set('kind', value as GalleryItem['kind'])} options={(['reel', 'photo'] as const).map((value) => ({ value, label: t(`gallery.kinds.${value}`) }))} />
            <NumberInput label={t('gallery.viewCount')} value={form.view_count} onChange={(value) => set('view_count', value)} error={error('view_count')} preview={(value) => t('gallery.views', { n: number(value) })} />
          </Pair>
          <TextInput label={t('gallery.url')} type="url" value={form.url} onChange={(value) => set('url', value)} error={error('url')} placeholder="https://www.facebook.com/reel/…" hint={t('gallery.urlHint')} />
          <Pair>
            <TextInput label={t('gallery.captionBn')} value={form.caption_bn} onChange={(value) => set('caption_bn', value)} error={error('caption_bn')} />
            <TextInput label={t('gallery.captionEn')} value={form.caption_en} onChange={(value) => set('caption_en', value)} error={error('caption_en')} />
          </Pair>
          <ImageField
            label={t('gallery.thumbnail')}
            media={form.thumbnail}
            error={error('media_id')}
            onChange={(media) => {
              set('thumbnail', media)
              set('media_id', media?.id ?? null)
            }}
          />
        </>
      )}
    />
  )
}
