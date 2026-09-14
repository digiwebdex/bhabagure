import { useTranslation } from 'react-i18next'

import { Pair, TextInput } from '../../../components/ui/fields'
import type { Media, TeamMember } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { ImageField } from '../lists/ImageField'
import { PublishedList } from '../lists/PublishedList'
import { MediaThumb } from '../media/media'

type TeamForm = Pick<TeamMember, 'name_bn' | 'name_en' | 'role_bn' | 'role_en' | 'employee_code' | 'photo_media_id'> & { photo: Media | null }

export function TeamPage() {
  const { t } = useTranslation()
  const { locale, digits } = useFormat()

  return (
    <PublishedList<TeamMember, TeamForm>
      resource="team"
      title={t('team.title')}
      subtitle={t('team.subtitle')}
      newLabel={t('team.new')}
      emptyTitle={t('team.empty')}
      emptyNote={t('team.emptyNote')}
      row={(member) => ({
        label: member.name_en,
        content: (
          <>
            <MediaThumb media={member.photo} className="size-12 rounded-full" />
            <span className="flex min-w-0 flex-col">
              <span className="truncate text-14 font-medium">{locale === 'bn' ? member.name_bn : member.name_en}</span>
              <span className="text-12 text-app-muted">
                {locale === 'bn' ? member.role_bn : member.role_en}
                {member.employee_code ? ` · ${digits(member.employee_code)}` : ''}
              </span>
            </span>
          </>
        ),
      })}
      toForm={(member) => ({
        name_bn: member?.name_bn ?? '',
        name_en: member?.name_en ?? '',
        role_bn: member?.role_bn ?? '',
        role_en: member?.role_en ?? '',
        employee_code: member?.employee_code ?? '',
        photo_media_id: member?.photo_media_id ?? null,
        photo: member?.photo ?? null,
      })}
      renderForm={({ form, set, error }) => (
        <>
          <Pair>
            <TextInput label={t('fields.nameBn')} value={form.name_bn} onChange={(value) => set('name_bn', value)} error={error('name_bn')} />
            <TextInput label={t('fields.nameEn')} value={form.name_en} onChange={(value) => set('name_en', value)} error={error('name_en')} />
          </Pair>
          <Pair>
            <TextInput label={t('team.roleBn')} value={form.role_bn} onChange={(value) => set('role_bn', value)} error={error('role_bn')} />
            <TextInput label={t('team.roleEn')} value={form.role_en} onChange={(value) => set('role_en', value)} error={error('role_en')} />
          </Pair>
          <TextInput label={t('team.employeeCode')} value={form.employee_code} onChange={(value) => set('employee_code', value)} error={error('employee_code')} placeholder="BH-EMP-003" hint={t('team.employeeCodeHint')} />
          <ImageField
            label={t('team.photo')}
            square
            media={form.photo}
            error={error('photo_media_id')}
            onChange={(media) => {
              set('photo', media)
              set('photo_media_id', media?.id ?? null)
            }}
          />
        </>
      )}
    />
  )
}
