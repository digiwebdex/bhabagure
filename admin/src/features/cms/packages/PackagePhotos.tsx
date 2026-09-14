import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { Badge, ReorderButtons } from '../../../components/ui/layout'
import { move } from '../../../lib/move'
import type { PackageImage } from '../../../lib/api/types'
import { MediaPicker, MediaThumb } from '../media/media'
import { packageActions, usePackageMutation } from './api'

/** Photos save immediately (they are their own records); the first photo is the card cover until another is chosen. */
export function PackagePhotos({ packageId, images }: { packageId: number; images: PackageImage[] }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [picking, setPicking] = useState(false)
  const add = usePackageMutation((mediaId: number) => packageActions.addImage(packageId, mediaId))
  const reorder = usePackageMutation(({ ids, cover }: { ids: number[]; cover: number }) => packageActions.reorderImages(packageId, ids, cover))
  const remove = usePackageMutation((imageId: number) => packageActions.removeImage(packageId, imageId))
  const cover = images.find((image) => image.is_cover) ?? images[0]
  const error = add.error ?? reorder.error ?? remove.error

  return (
    <div className="flex flex-col gap-3">
      {images.length === 0 ? <p className="m-0 text-13 text-app-muted">{t('packages.noPhotos')}</p> : (
        <div className="grid-auto-fit-half-160 grid gap-3.5">
          {images.map((image, index) => (
            <figure key={image.id} className="m-0 flex min-w-0 flex-col gap-1.75">
              <MediaThumb media={image.media} className="aspect-4/3 w-full rounded-13" />
              <figcaption className="flex flex-wrap items-center justify-between gap-1.5">
                {image.id === cover?.id ? (
                  <Badge tone="green">{t('packages.cover')}</Badge>
                ) : (
                  <button type="button" className={buttonClass('outline', 'sm', 'px-2 py-1')} onClick={() => reorder.mutate({ ids: images.map((i) => i.id), cover: image.id })}>
                    {t('packages.makeCover')}
                  </button>
                )}
                {image.media.is_placeholder ? <Badge tone="orange">{t('media.placeholder')}</Badge> : null}
                <span className="flex items-center gap-1">
                  <ReorderButtons index={index} count={images.length} label={t('packages.photoN', { n: index + 1 })} onMove={(from, to) => reorder.mutate({ ids: move(images, from, to).map((i) => i.id), cover: cover.id })} />
                  <button
                    type="button"
                    aria-label={t('common.remove')}
                    className="size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-12 text-amber hover:border-red hover:text-red"
                    onClick={() => remove.mutate(image.id, { onSuccess: () => toast(t('packages.photoRemoved')) })}
                  >
                    ×
                  </button>
                </span>
              </figcaption>
            </figure>
          ))}
        </div>
      )}
      <ErrorNotice error={error} />
      <button type="button" className={buttonClass('primary', 'sm', 'self-start')} onClick={() => setPicking(true)}>
        + {t('packages.addPhoto')}
      </button>
      <span className="text-12 leading-1.6 text-app-muted">{t('packages.photoHelp')}</span>
      <MediaPicker
        open={picking}
        onClose={() => setPicking(false)}
        onPick={(media) => add.mutate(media.id, { onSuccess: () => toast(t('packages.photoAdded')) })}
      />
    </div>
  )
}
