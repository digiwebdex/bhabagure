import { useTranslation } from 'react-i18next'

import { NumberInput, Pair, SelectInput, TextArea, TextInput } from '../../../components/ui/fields'
import type { Review } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { PublishedList } from '../lists/PublishedList'
import { usePackages } from '../packages/api'

type ReviewForm = Pick<Review, 'quote_bn' | 'quote_en' | 'reviewer_name' | 'trip_label_bn' | 'trip_label_en' | 'rating' | 'travelled_on' | 'tour_package_id'>

export function ReviewsPage() {
  const { t } = useTranslation()
  const { number, locale } = useFormat()
  const packages = usePackages('all', '', '')

  return (
    <PublishedList<Review, ReviewForm>
      resource="reviews"
      title={t('reviews.title')}
      subtitle={t('reviews.subtitle')}
      newLabel={t('reviews.new')}
      emptyTitle={t('reviews.empty')}
      emptyNote={t('reviews.emptyNote')}
      note={<p className="m-0 text-13 leading-1.6 text-app-muted">{t('reviews.realOnly')}</p>}
      row={(review) => ({
        label: review.reviewer_name,
        content: (
          <span className="flex min-w-0 flex-col gap-0.5">
            <span className="flex flex-wrap items-center gap-2 text-14 font-medium">
              {review.reviewer_name}
              <span aria-label={t('reviews.stars', { n: number(review.rating) })} className="text-13 text-orange">
                {'★'.repeat(review.rating)}
                <span className="text-app-line">{'★'.repeat(5 - review.rating)}</span>
              </span>
            </span>
            <span className="line-clamp-2 text-13 text-app-muted">{locale === 'en' && review.quote_en ? review.quote_en : review.quote_bn}</span>
          </span>
        ),
      })}
      toForm={(review) => ({
        quote_bn: review?.quote_bn ?? '',
        quote_en: review?.quote_en ?? '',
        reviewer_name: review?.reviewer_name ?? '',
        trip_label_bn: review?.trip_label_bn ?? '',
        trip_label_en: review?.trip_label_en ?? '',
        rating: review?.rating ?? 5,
        travelled_on: review?.travelled_on ?? null,
        tour_package_id: review?.tour_package_id ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextArea label={t('reviews.quoteBn')} rows={4} value={form.quote_bn} onChange={(value) => set('quote_bn', value)} error={error('quote_bn')} />
            <TextArea label={t('reviews.quoteEn')} rows={4} value={form.quote_en} onChange={(value) => set('quote_en', value)} error={error('quote_en')} hint={t('reviews.quoteEnHint')} />
          </Pair>
          <Pair>
            <TextInput label={t('reviews.reviewer')} value={form.reviewer_name} onChange={(value) => set('reviewer_name', value)} error={error('reviewer_name')} />
            <NumberInput label={t('reviews.rating')} value={form.rating} onChange={(value) => set('rating', value ?? 0)} error={error('rating')} hint={t('reviews.ratingHint')} />
          </Pair>
          <Pair>
            <TextInput label={t('reviews.tripLabelBn')} value={form.trip_label_bn} onChange={(value) => set('trip_label_bn', value)} error={error('trip_label_bn')} />
            <TextInput label={t('reviews.tripLabelEn')} value={form.trip_label_en} onChange={(value) => set('trip_label_en', value)} error={error('trip_label_en')} placeholder="Mustang Adventure · Aug 2026" />
          </Pair>
          <Pair>
            <TextInput label={t('reviews.travelledOn')} type="date" value={form.travelled_on} onChange={(value) => set('travelled_on', value || null)} error={error('travelled_on')} />
            <SelectInput
              label={t('reviews.package')}
              value={String(form.tour_package_id ?? '')}
              onChange={(value) => set('tour_package_id', value ? Number(value) : null)}
              error={error('tour_package_id')}
              options={[{ value: '', label: t('reviews.noPackage') }, ...(packages.data?.data ?? []).map((pkg) => ({ value: String(pkg.id), label: `${pkg.code} · ${pkg.title_en}` }))]}
            />
          </Pair>
        </>
      )}
    />
  )
}
