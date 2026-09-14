import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import type { Media } from '../../../lib/api/types'
import { MediaPicker, MediaThumb } from '../media/media'

/** One image chosen from the library (team photo, gallery thumbnail). */
export function ImageField({ label, media, onChange, error, square = false }: { label: string; media: Media | null; onChange: (media: Media | null) => void; error?: string; square?: boolean }) {
  const { t } = useTranslation()
  const [picking, setPicking] = useState(false)

  return (
    <div className="flex flex-col gap-1.25">
      <span className="text-13 text-app-muted">{label}</span>
      <div className="flex flex-wrap items-center gap-3">
        <MediaThumb media={media} className={square ? 'size-24 rounded-full' : 'aspect-4/3 w-40'} />
        <span className="flex gap-2">
          <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setPicking(true)}>
            {media ? t('common.change') : t('common.choose')}
          </button>
          {media ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => onChange(null)}>
              {t('common.remove')}
            </button>
          ) : null}
        </span>
      </div>
      {error ? <span role="alert" className="text-12 font-semibold text-red">{error}</span> : null}
      <MediaPicker open={picking} onClose={() => setPicking(false)} onPick={onChange} />
    </div>
  )
}
