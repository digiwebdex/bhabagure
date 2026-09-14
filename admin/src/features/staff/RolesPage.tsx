import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Pair, Switch, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Chips, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { roleActions, roleLabel, useRoleAction, useRoles, type PermissionRow, type RoleRow } from './api'

/** The design's staff-visibility toggles (System → Roles): each is one permission of the selected role. */
const VISIBILITY = ['commission.view_own', 'ledger.view_company_balance', 'commission.view_all', 'bookings.view_own', 'reports.profit_loss']

/**
 * System → Roles & permissions (docs/phase-7-hr-attendance-bonus-wallet.md §4.1), the super admin's screen: roles and who
 * holds them, custom roles, the permission matrix and the staff-visibility toggles. Every change is audited, and a
 * permission removed here is never handed back by a deploy.
 */
export function RolesPage() {
  const { t } = useTranslation()
  const { locale, number } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const roles = useRoles()
  const [selected, setSelected] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [renaming, setRenaming] = useState<RoleRow | null>(null)
  const setPermission = useRoleAction(roleActions.set)
  const remove = useRoleAction(roleActions.remove)

  if (roles.isPending) return <Loading />
  if (roles.isError) return <ErrorNotice error={roles.error} />
  const { roles: list, permissions } = roles.data.data
  const role = list.find((row) => row.name === selected) ?? list.find((row) => !row.all_permissions) ?? list[0]
  const modules = [...new Set(permissions.map((permission) => permission.module))]
  const permissionLabel = (permission: PermissionRow) => (locale === 'en' ? permission.name_en : permission.name_bn)
  const has = (row: RoleRow, name: string) => row.all_permissions || row.permissions.includes(name)

  const toggle = (permission: PermissionRow, granted: boolean) =>
    setPermission.mutate(
      { id: role.id, permission: permission.name, granted },
      { onSuccess: () => toast(t(granted ? 'roles.granted' : 'roles.revoked', { permission: permissionLabel(permission), role: roleLabel(role, locale) })) },
    )

  const actionsFor = (row: RoleRow): RowAction[] => {
    const systemReason = row.is_system ? t('roles.systemFixed') : undefined
    return [
      { key: 'permissions', icon: '☰', label: t('roles.editPermissions'), tone: 'blue', disabledReason: row.all_permissions ? t('roles.allPermissions') : undefined, onSelect: () => setSelected(row.name) },
      { key: 'rename', icon: '✎', label: t('roles.rename'), tone: 'muted', disabledReason: systemReason, onSelect: () => setRenaming(row) },
      {
        key: 'delete',
        icon: '✕',
        label: t('roles.delete'),
        tone: 'red',
        disabledReason: systemReason ?? (row.users > 0 ? t('roles.inUse', { count: row.users, n: number(row.users) }) : undefined),
        onSelect: async () => {
          if (!(await confirm(t('roles.deleteConfirm', { role: roleLabel(row, locale) })))) return
          remove.mutate(row.id, { onSuccess: () => toast(t('roles.deleted')), onError: (error) => toast(error.message, 'error') })
        },
      },
    ]
  }

  const columns: Column<RoleRow>[] = [
    {
      key: 'role',
      header: t('roles.columns.role'),
      cell: (row) => (
        <span className="flex flex-col gap-0.5">
          <span className="font-medium">{roleLabel(row, locale)}</span>
          <span className="text-12 text-app-muted">{row.is_system ? t('roles.system') : t('roles.custom')}</span>
        </span>
      ),
    },
    { key: 'users', header: t('roles.columns.users'), align: 'right', cell: (row) => <span className="font-display">{number(row.users)}</span> },
    {
      key: 'scope',
      header: t('roles.columns.scope'),
      cell: (row) => (
        <span className="block max-w-80 truncate text-12 text-app-muted">
          {row.all_permissions ? t('roles.wholeSystem') : modules.filter((module) => permissions.some((permission) => permission.module === module && row.permissions.includes(permission.name))).map((module) => t(`roles.modules.${module}`)).join(', ') || '—'}
        </span>
      ),
    },
    {
      key: 'balance',
      header: t('roles.columns.companyBalance'),
      cell: (row) => (has(row, 'ledger.view_company_balance') ? <Badge tone="blue">{t('roles.sees')}</Badge> : <Badge tone="green">{t('roles.notSees')}</Badge>),
    },
  ]

  const permissionSwitch = (permission: PermissionRow) =>
    permission.reserved ? (
      // Role management can't be granted to a role: shown, not switchable.
      <span key={permission.name} className="flex flex-col text-14">
        {permissionLabel(permission)}
        <span className="text-12 text-app-muted">{t('roles.reserved')}</span>
      </span>
    ) : (
      <Switch
        key={permission.name}
        label={permissionLabel(permission)}
        checked={has(role, permission.name)}
        onChange={(granted) => {
          if (!setPermission.isPending) toggle(permission, granted)
        }}
      />
    )

  return (
    <>
      <PageHeader
        title={t('roles.title')}
        subtitle={t('roles.subtitle')}
        actions={
          <button type="button" className={buttonClass('cta')} onClick={() => setCreating(true)}>
            {t('roles.new')}
          </button>
        }
      />
      <Card padded={false} className="overflow-hidden">
        <DataTable label={t('roles.title')} testId="roles-table" columns={columns} rows={list} rowKey={(row) => row.id} rowLabel={(row) => roleLabel(row, locale)} actions={actionsFor} onRowClick={(row) => setSelected(row.name)} isSelected={(row) => row.id === role.id} />
      </Card>

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <Card>
          <CardTitle bn="অনুমতির ম্যাট্রিক্স" en="Permission matrix" />
          <Chips label={t('roles.columns.role')} value={role.name} onChange={setSelected} options={list.map((row) => ({ value: row.name, label: roleLabel(row, locale) }))} />
          {role.all_permissions ? (
            <p className="m-0 text-13.5 text-app-muted">{t('roles.allPermissions')}</p>
          ) : (
            <div className="flex flex-col gap-4" data-testid="permission-matrix">
              {modules.map((module) => (
                <fieldset key={module} className="m-0 flex flex-col gap-2 border-0 border-t border-app-line p-0 pt-3">
                  <legend className="mb-1 font-display text-12 font-semibold tracking-eyebrow text-app-muted uppercase">{t(`roles.modules.${module}`)}</legend>
                  {permissions.filter((permission) => permission.module === module).map(permissionSwitch)}
                </fieldset>
              ))}
            </div>
          )}
          {setPermission.error ? <ErrorNotice error={setPermission.error} /> : null}
        </Card>
        <Card>
          <CardTitle bn="স্টাফ দৃশ্যমানতা" en="Staff visibility" />
          <p className="m-0 text-13 leading-1.55 text-app-muted">{t('roles.visibilityNote', { role: roleLabel(role, locale) })}</p>
          {role.all_permissions ? <p className="m-0 text-13.5 text-app-muted">{t('roles.allPermissions')}</p> : permissions.filter((permission) => VISIBILITY.includes(permission.name)).map(permissionSwitch)}
        </Card>
      </div>
      {creating ? <NewRoleDialog permissions={permissions} modules={modules} onClose={() => setCreating(false)} onCreated={(name) => { setCreating(false); setSelected(name) }} /> : null}
      {renaming ? <RenameRoleDialog role={renaming} onClose={() => setRenaming(null)} /> : null}
      {element}
    </>
  )
}

function NewRoleDialog({ permissions, modules, onClose, onCreated }: { permissions: PermissionRow[]; modules: string[]; onClose: () => void; onCreated: (name: string) => void }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const toast = useToast()
  const [nameEn, setNameEn] = useState('')
  const [nameBn, setNameBn] = useState('')
  const [chosen, setChosen] = useState<string[]>([])
  const create = useRoleAction(roleActions.create)
  const fieldError = (name: string) => (create.error instanceof ApiError ? create.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('roles.newTitle')} wide>
      <Pair>
        <TextInput label={t('roles.nameEn')} value={nameEn} onChange={setNameEn} error={fieldError('name_en')} maxLength={120} />
        <TextInput label={t('roles.nameBn')} value={nameBn} onChange={setNameBn} error={fieldError('name_bn')} maxLength={120} />
      </Pair>
      <p className="m-0 text-13 text-app-muted">{t('roles.newNote')}</p>
      <div className="grid-auto-fit-260 grid gap-4">
        {modules.map((module) => (
          <fieldset key={module} className="m-0 flex flex-col gap-1.5 border-0 p-0">
            <legend className="mb-1 font-display text-12 font-semibold tracking-eyebrow text-app-muted uppercase">{t(`roles.modules.${module}`)}</legend>
            {permissions.filter((permission) => permission.module === module && !permission.reserved).map((permission) => (
              <label key={permission.name} className="flex cursor-pointer items-start gap-2 text-13">
                <input type="checkbox" className="mt-0.5" checked={chosen.includes(permission.name)} onChange={(event) => setChosen(event.target.checked ? [...chosen, permission.name] : chosen.filter((name) => name !== permission.name))} />
                {locale === 'en' ? permission.name_en : permission.name_bn}
              </label>
            ))}
          </fieldset>
        ))}
      </div>
      {create.error && !(create.error instanceof ApiError && create.error.status === 422) ? <ErrorNotice error={create.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={nameEn.trim() === '' || nameBn.trim() === '' || create.isPending}
          onClick={() =>
            create.mutate(
              { name_en: nameEn.trim(), name_bn: nameBn.trim(), permissions: chosen },
              {
                onSuccess: (response) => {
                  toast(t('roles.created'))
                  const made = response.data.roles.find((row) => row.name_en === nameEn.trim())
                  onCreated(made?.name ?? '')
                },
              },
            )
          }
        >
          {t('roles.create')}
        </button>
      </div>
    </Dialog>
  )
}

function RenameRoleDialog({ role, onClose }: { role: RoleRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const toast = useToast()
  const [nameEn, setNameEn] = useState(role.name_en)
  const [nameBn, setNameBn] = useState(role.name_bn)
  const rename = useRoleAction(roleActions.rename)
  const fieldError = (name: string) => (rename.error instanceof ApiError ? rename.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('roles.renameTitle', { role: roleLabel(role, locale) })}>
      <Pair>
        <TextInput label={t('roles.nameEn')} value={nameEn} onChange={setNameEn} error={fieldError('name_en')} maxLength={120} />
        <TextInput label={t('roles.nameBn')} value={nameBn} onChange={setNameBn} error={fieldError('name_bn')} maxLength={120} />
      </Pair>
      {rename.error && !(rename.error instanceof ApiError && rename.error.status === 422) ? <ErrorNotice error={rename.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={nameEn.trim() === '' || nameBn.trim() === '' || rename.isPending}
          onClick={() => rename.mutate({ id: role.id, name_en: nameEn.trim(), name_bn: nameBn.trim() }, { onSuccess: () => { toast(t('roles.renamed')); onClose() } })}
        >
          {t('common.save')}
        </button>
      </div>
    </Dialog>
  )
}
