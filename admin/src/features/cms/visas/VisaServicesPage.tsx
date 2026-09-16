import { useTranslation } from 'react-i18next'

import { NumberInput, Pair, TextArea, TextInput } from '../../../components/ui/fields'
import type { VisaService } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { PublishedList } from '../lists/PublishedList'

type VisaForm = Omit<VisaService, 'id' | 'sort_order' | 'status'>

/**
 * Visa services (docs/phase-8-visa-quotes-pricing-downloads.md §4.C): one entry per country and visa type. Published ones
 * show in the website's Visa section, on their own page and in the search panel's Visa tab; the requirements PDF is made
 * from the same text.
 */
export function VisaServicesPage() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const lines = (text: string | null) => (text ?? '').split(/\r?\n/).filter((line) => line.trim() !== '').length

  return (
    <PublishedList<VisaService, VisaForm>
      resource="visas"
      title={t('visas.title')}
      subtitle={t('visas.subtitle')}
      newLabel={t('visas.new')}
      emptyTitle={t('visas.empty')}
      emptyNote={t('visas.emptyNote')}
      row={(visa) => ({
        label: `${visa.country_en} · ${visa.visa_type_en}`,
        content: (
          <span className="flex min-w-0 flex-col gap-0.5">
            <span className="truncate text-14 font-medium">
              {visa.country_code ? `${visa.country_code} · ` : ''}{visa.country_en || visa.country_bn} · {visa.visa_type_en || visa.visa_type_bn}
            </span>
            <span className="text-12 text-app-muted">
              {visa.price === null ? t('visas.onRequest') : bdt(visa.price)}
              {' · '}
              {(visa.processing_en || visa.processing_bn) ?? t('visas.noProcessing')}
              {' · '}
              {t('visas.requirementCount', { count: lines(visa.requirements_en || visa.requirements_bn) })}
            </span>
          </span>
        ),
      })}
      toForm={(visa) => ({
        slug: visa?.slug ?? '',
        country_code: visa?.country_code ?? '',
        country_bn: visa?.country_bn ?? '',
        country_en: visa?.country_en ?? '',
        visa_type_bn: visa?.visa_type_bn ?? '',
        visa_type_en: visa?.visa_type_en ?? '',
        price: visa?.price ?? null,
        processing_bn: visa?.processing_bn ?? '',
        processing_en: visa?.processing_en ?? '',
        stay_bn: visa?.stay_bn ?? '',
        stay_en: visa?.stay_en ?? '',
        requirements_bn: visa?.requirements_bn ?? '',
        requirements_en: visa?.requirements_en ?? '',
        notes_bn: visa?.notes_bn ?? '',
        notes_en: visa?.notes_en ?? '',
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextInput label={t('visas.countryBn')} value={form.country_bn} onChange={(value) => set('country_bn', value)} error={error('country_bn')} placeholder="থাইল্যান্ড" />
            <TextInput label={t('visas.countryEn')} value={form.country_en} onChange={(value) => set('country_en', value)} error={error('country_en')} placeholder="Thailand" />
          </Pair>
          <Pair>
            <TextInput label={t('visas.typeBn')} value={form.visa_type_bn} onChange={(value) => set('visa_type_bn', value)} error={error('visa_type_bn')} placeholder="টুরিস্ট ভিসা" />
            <TextInput label={t('visas.typeEn')} value={form.visa_type_en} onChange={(value) => set('visa_type_en', value)} error={error('visa_type_en')} placeholder="Tourist visa" />
          </Pair>
          <Pair>
            <TextInput
              label={t('visas.countryCode')}
              value={form.country_code}
              onChange={(value) => set('country_code', value.toUpperCase().slice(0, 2))}
              error={error('country_code')}
              placeholder="TH"
              hint={t('visas.countryCodeHint')}
            />
            <NumberInput label={t('visas.price')} value={form.price} onChange={(value) => set('price', value)} error={error('price')} hint={form.price !== null ? `${bdt(form.price)} · ${t('visas.priceHint')}` : t('visas.priceHint')} />
          </Pair>
          <Pair>
            <TextInput label={t('visas.processingBn')} value={form.processing_bn} onChange={(value) => set('processing_bn', value)} error={error('processing_bn')} placeholder="৭–১০ কর্মদিবস" />
            <TextInput label={t('visas.processingEn')} value={form.processing_en} onChange={(value) => set('processing_en', value)} error={error('processing_en')} placeholder="7–10 working days" />
          </Pair>
          <Pair>
            <TextInput label={t('visas.stayBn')} value={form.stay_bn} onChange={(value) => set('stay_bn', value)} error={error('stay_bn')} />
            <TextInput label={t('visas.stayEn')} value={form.stay_en} onChange={(value) => set('stay_en', value)} error={error('stay_en')} placeholder="Single entry, up to 60 days" />
          </Pair>
          <Pair>
            <TextArea label={t('visas.requirementsBn')} value={form.requirements_bn} onChange={(value) => set('requirements_bn', value)} error={error('requirements_bn')} rows={7} hint={t('visas.requirementsHint')} />
            <TextArea label={t('visas.requirementsEn')} value={form.requirements_en} onChange={(value) => set('requirements_en', value)} error={error('requirements_en')} rows={7} hint={t('visas.requirementsHint')} />
          </Pair>
          <Pair>
            <TextArea label={t('visas.notesBn')} value={form.notes_bn} onChange={(value) => set('notes_bn', value)} error={error('notes_bn')} rows={3} />
            <TextArea label={t('visas.notesEn')} value={form.notes_en} onChange={(value) => set('notes_en', value)} error={error('notes_en')} rows={3} />
          </Pair>
          <TextInput label={t('visas.slug')} value={form.slug} onChange={(value) => set('slug', value)} error={error('slug')} placeholder="thailand-tourist-visa" hint={t('visas.slugHint')} />
        </>
      )}
    />
  )
}
