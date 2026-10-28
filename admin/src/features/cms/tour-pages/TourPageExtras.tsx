import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { Pair, SelectInput, TextInput } from '../../../components/ui/fields'
import { Card, CardTitle, ReorderButtons } from '../../../components/ui/layout'
import { ImageField } from '../lists/ImageField'
import { FEATURED_REVIEWS, VIDEOS_PER_SECTION, type ReviewChoice, type VideoForm, type VideoSection } from './api'

const move = <T,>(list: T[], from: number, to: number) => {
  const next = [...list]
  const [item] = next.splice(from, 1)
  next.splice(to, 0, item)
  return next
}

/**
 * The page's reviews (docs/tour-landing-pages.md §9): the tour's published ones show by themselves; staff may put up to
 * twelve — this tour's or another's — first, in their own order.
 */
export function FeaturedReviewsCard({ choices, featured, onChange, error }: { choices: ReviewChoice[]; featured: number[]; onChange: (ids: number[]) => void; error?: string }) {
  const { t } = useTranslation()
  const byId = new Map(choices.map((choice) => [choice.id, choice]))
  const label = (choice: ReviewChoice) =>
    `${choice.reviewer_name} · ${'★'.repeat(choice.rating)}${choice.this_tour ? '' : ` · ${choice.package_title ?? t('tourPages.noTour')}`}`
  const thisTour = choices.filter((choice) => choice.this_tour).length

  return (
    <Card>
      <CardTitle title={t('tourPages.reviews')} />
      <p className="m-0 text-12 leading-1.55 text-app-muted">{t('tourPages.reviewsHint', { count: thisTour })}</p>
      <Link to="/reviews" className="text-13">
        {t('tourPages.editReviews')} →
      </Link>
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
      {featured.length > 0 ? (
        <ol className="m-0 flex list-none flex-col gap-2 p-0" data-testid="tour-page-featured-reviews">
          {featured.map((id, index) => {
            const choice = byId.get(id)
            if (!choice) return null
            return (
              <li key={id} className="flex items-start gap-2.5 rounded-11 border border-app-line p-2.5">
                <ReorderButtons index={index} count={featured.length} onMove={(from, to) => onChange(move(featured, from, to))} label={choice.reviewer_name} />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span className="text-13 font-semibold">{label(choice)}</span>
                  <span className="line-clamp-2 text-12 text-app-muted">{choice.quote}</span>
                </span>
                <button type="button" className={buttonClass('ghost', 'sm')} onClick={() => onChange(featured.filter((other) => other !== id))}>
                  {t('common.remove')}
                </button>
              </li>
            )
          })}
        </ol>
      ) : null}
      {choices.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('tourPages.noReviews')}</p>
      ) : featured.length < FEATURED_REVIEWS ? (
        <SelectInput
          label={t('tourPages.featureReview')}
          value=""
          onChange={(value) => value && onChange([...featured, Number(value)])}
          options={[
            { value: '', label: t('tourPages.chooseReview') },
            ...choices.filter((choice) => !featured.includes(choice.id)).map((choice) => ({ value: String(choice.id), label: label(choice) })),
          ]}
        />
      ) : null}
    </Card>
  )
}

/** Guests' video feedback or the travel vlogs: YouTube or Facebook links, each with optional titles and picture. */
export function VideosCard({
  section,
  videos,
  offset,
  onChange,
  error,
}: {
  section: VideoSection
  videos: VideoForm[]
  /** Where this section's videos start in the saved list, for their errors. */
  offset: number
  onChange: (videos: VideoForm[]) => void
  error: (path: string) => string | undefined
}) {
  const { t } = useTranslation()
  const update = (index: number, patch: Partial<VideoForm>) => onChange(videos.map((video, i) => (i === index ? { ...video, ...patch } : video)))
  const heading = section === 'guest' ? t('tourPages.guestVideos') : t('tourPages.vlogs')

  return (
    <Card>
      <CardTitle title={heading} />
      <p className="m-0 text-12 leading-1.55 text-app-muted">{section === 'guest' ? t('tourPages.guestVideosHint') : t('tourPages.vlogsHint')}</p>
      {error('videos') && section === 'guest' ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error('videos')}
        </span>
      ) : null}
      <ol className="m-0 flex list-none flex-col gap-3 p-0" data-testid={`tour-page-videos-${section}`}>
        {videos.map((video, index) => {
          const at = `videos.${offset + index}`
          return (
            <li key={index} className="flex flex-col gap-2.5 rounded-13 border border-app-line p-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-13 font-semibold">{t('tourPages.videoN', { n: index + 1 })}</span>
                <span className="flex items-center gap-2">
                  <ReorderButtons index={index} count={videos.length} onMove={(from, to) => onChange(move(videos, from, to))} label={t('tourPages.videoN', { n: index + 1 })} />
                  <button type="button" className={buttonClass('ghost', 'sm')} onClick={() => onChange(videos.filter((_, i) => i !== index))}>
                    {t('common.remove')}
                  </button>
                </span>
              </div>
              <TextInput label={t('tourPages.videoUrl')} value={video.url} onChange={(value) => update(index, { url: value })} error={error(`${at}.url`)} hint={t('tourPages.videoUrlHint')} placeholder="https://www.youtube.com/watch?v=…" />
              <Pair>
                <TextInput label={t('tourPages.videoTitleBn')} value={video.title_bn} onChange={(value) => update(index, { title_bn: value })} error={error(`${at}.title_bn`)} />
                <TextInput label={t('tourPages.videoTitleEn')} value={video.title_en} onChange={(value) => update(index, { title_en: value })} error={error(`${at}.title_en`)} />
              </Pair>
              <ImageField label={t('tourPages.videoPicture')} media={video.thumbnail} onChange={(media) => update(index, { thumbnail: media })} error={error(`${at}.thumbnail_media_id`)} />
            </li>
          )
        })}
      </ol>
      {videos.length < VIDEOS_PER_SECTION ? (
        <button
          type="button"
          className={buttonClass('outline', 'sm', 'self-start')}
          onClick={() => onChange([...videos, { url: '', title_bn: '', title_en: '', thumbnail: null }])}
          data-testid={`tour-page-add-video-${section}`}
        >
          + {t('tourPages.addVideo')}
        </button>
      ) : null}
    </Card>
  )
}
