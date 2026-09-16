import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useAuth } from '../../app/auth'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { accountActions, ACCOUNT_TYPES, useAccountAction, useChartOfAccounts, type AccountRow, type AccountType } from './api'

/**
 * Chart of accounts (docs/phase-9-accounts.md §2): every account the books use, grouped by kind, with its balance.
 * The accounts the software posts to are marked and can only be reworded; staff add their own for anything else.
 */
export function ChartOfAccountsPage() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const { can } = useAuth()
  const chart = useChartOfAccounts()
  const [editing, setEditing] = useState<AccountRow | 'new' | null>(null)
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const remove = useAccountAction((id: number) => accountActions.remove(id)())

  if (chart.isPending) return <Loading />
  if (chart.isError) return <ErrorNotice error={chart.error} />
  const manage = can('accounts.manage')

  return (
    <>
      <PageHeader
        title={t('accounts.title')}
        subtitle={t('accounts.subtitle')}
        actions={
          manage ? (
            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setEditing('new')}>
              {t('accounts.add')}
            </button>
          ) : undefined
        }
      />

      <div className="grid-auto-fit-200 grid gap-3.5" data-testid="account-totals">
        {ACCOUNT_TYPES.map((type) => (
          <div key={type} className="flex flex-col gap-1.25 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">
            <span className="text-13 text-app-muted">{t(`accounts.types.${type}`)}</span>
            <span className="font-display text-22 font-extrabold">{bdt(chart.data.meta.totals[type] ?? 0)}</span>
          </div>
        ))}
      </div>

      {ACCOUNT_TYPES.map((type) => {
        const rows = chart.data.data.filter((account) => account.type === type)
        return (
          <Card key={type} padded={false} className="overflow-hidden">
            <div className="border-b border-app-line p-3.5">
              <CardTitle title={t(`accounts.types.${type}`)} aside={<span className="text-12 text-app-muted">{t('accounts.typeNote', { count: rows.length })}</span>} />
            </div>
            {rows.length === 0 ? (
              <EmptyState title={t('accounts.emptyType')} />
            ) : (
              <ul className="m-0 flex list-none flex-col p-0" data-testid={`accounts-${type}`}>
                {rows.map((account) => (
                  <li key={account.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line px-4 py-3 last:border-b-0">
                    <span className="flex min-w-0 flex-col gap-0.5">
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-display text-12 text-app-muted">{account.code}</span>
                        <span className="font-medium">{account.name}</span>
                        {account.is_money ? <Badge tone="green">{t('accounts.money')}</Badge> : null}
                        {account.is_system ? <Badge tone="slate">{t('accounts.system')}</Badge> : null}
                      </span>
                      {account.description ? <span className="text-12 text-app-muted">{account.description}</span> : null}
                    </span>
                    <span className="flex items-center gap-3">
                      <span className="flex flex-col items-end">
                        <span className="font-display font-bold">{bdt(account.balance)}</span>
                        <span className="text-11 text-app-muted">{t('accounts.entries', { count: account.entries })}</span>
                      </span>
                      {manage ? (
                        <span className="flex gap-1.5">
                          <button type="button" className={buttonClass('outline', 'sm', 'px-2.5 py-1 text-12')} onClick={() => setEditing(account)}>
                            {t('common.edit')}
                          </button>
                          {!account.is_system && account.entries === 0 ? (
                            <button
                              type="button"
                              className={buttonClass('danger', 'sm', 'px-2.5 py-1 text-12')}
                              onClick={async () => {
                                if (!(await confirm(t('accounts.deleteConfirm', { name: account.name })))) return
                                remove.mutate(account.id, { onSuccess: () => toast(t('accounts.deleted')), onError: (error) => toast(error.message, 'error') })
                              }}
                            >
                              {t('common.delete')}
                            </button>
                          ) : null}
                        </span>
                      ) : null}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )
      })}

      {editing ? <AccountDialog account={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
      {confirmDialog}
    </>
  )
}

function AccountDialog({ account, onClose }: { account: AccountRow | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({
    name: account?.name ?? '',
    type: (account?.type ?? 'expense') as AccountType,
    description: account?.description ?? '',
    code: account?.code ?? '',
  })
  const save = useAccountAction(account ? accountActions.update(account.id) : accountActions.create)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const system = account?.is_system ?? false

  return (
    <Dialog open onClose={onClose} title={account ? t('accounts.editTitle') : t('accounts.addTitle')}>
      <TextInput label={t('accounts.name')} value={form.name} onChange={(name) => setForm({ ...form, name })} error={fieldError('name')} />
      <SelectInput
        label={t('accounts.type')}
        value={form.type}
        onChange={(type) => setForm({ ...form, type: type as AccountType })}
        options={ACCOUNT_TYPES.map((type) => ({ value: type, label: t(`accounts.types.${type}`) }))}
        disabled={system}
        error={fieldError('type')}
      />
      <TextInput
        label={t('accounts.code')}
        value={form.code}
        onChange={(code) => setForm({ ...form, code })}
        disabled={system}
        hint={system ? t('accounts.systemNote') : t('accounts.codeHint')}
        error={fieldError('code')}
      />
      <TextArea label={t('accounts.description')} value={form.description} onChange={(description) => setForm({ ...form, description })} rows={2} error={fieldError('description')} />
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!form.name.trim() || save.isPending}
          onClick={() =>
            save.mutate(
              { name: form.name.trim(), type: form.type, description: form.description.trim() || null, code: form.code.trim() || null },
              { onSuccess: () => { toast(t('common.saved')); onClose() } },
            )
          }
        >
          {save.isPending ? t('common.saving') : t('common.save')}
        </button>
      </div>
    </Dialog>
  )
}
