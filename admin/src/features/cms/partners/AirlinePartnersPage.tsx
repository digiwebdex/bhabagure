import { useTranslation } from 'react-i18next'

import { Pair, TextInput } from '../../../components/ui/fields'
import type { AirlinePartner, Media } from '../../../lib/api/types'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'
import { MediaThumb } from '../media/media'

type PartnerForm = Pick<AirlinePartner, 'name_bn' | 'name_en' | 'media_id' | 'website_url'> & { logo: Media | null }

/**
 * Airline partners (docs/partners-and-payments.md): the airlines the agency books, shown as a band of logos above the
 * footer on the home page. A partner is its logo, so one is needed before it can be published.
 */
export function AirlinePartnersPage() {
  const { t } = useTranslation()

  return (
    <PublishedList<AirlinePartner, PartnerForm>
      resource="airline-partners"
      title={t('partners.title')}
      subtitle={t('partners.subtitle')}
      newLabel={t('partners.new')}
      emptyTitle={t('partners.empty')}
      emptyNote={t('partners.emptyNote')}
      row={(partner) => ({
        label: partner.name_en,
        content: (
          <>
            <MediaThumb media={partner.logo} className="aspect-video w-20 bg-white object-contain" />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="truncate text-14 font-medium">{partner.name_en}</span>
              <span className="truncate text-12 text-app-muted">{partner.website_url ?? t('partners.noLink')}</span>
            </span>
          </>
        ),
      })}
      toForm={(partner) => ({
        name_bn: partner?.name_bn ?? '',
        name_en: partner?.name_en ?? '',
        media_id: partner?.media_id ?? null,
        website_url: partner?.website_url ?? '',
        logo: partner?.logo ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextInput label={t('partners.nameBn')} value={form.name_bn} onChange={(value) => set('name_bn', value)} error={error('name_bn')} />
            <TextInput label={t('partners.nameEn')} value={form.name_en} onChange={(value) => set('name_en', value)} error={error('name_en')} />
          </Pair>
          <TextInput label={t('partners.website')} type="url" value={form.website_url} onChange={(value) => set('website_url', value)} error={error('website_url')} placeholder="https://" hint={t('partners.websiteHint')} />
          <ImageField
            label={t('partners.logo')}
            media={form.logo}
            error={error('media_id')}
            onChange={(media) => {
              set('logo', media)
              set('media_id', media?.id ?? null)
            }}
          />
        </>
      )}
    />
  )
}
