import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, Switch, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, Loading, PageHeader } from '../../components/ui/layout'
import { useAuth } from '../../app/auth'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentOptions, usePaymentsMutation } from '../payments/api'
import { accountActions, ACCOUNT_TYPES, useAccountAction, useChartOfAccounts, type AccountRow, type AccountSection, type AccountType } from './api'

/** The kinds across the top, in the order the client's books read them. */
const TABS: AccountType[] = ['asset', 'liability', 'income', 'expense', 'equity']

/**
 * Chart of accounts (docs/phase-9-accounts.md §2): one kind at a time, divided into the sections the accounts are
 * looked for under — Cash and Bank, Operating Expense and the rest — each with its balance and when it was last used.
 * The accounts the software posts to are marked and can only be reworded; staff add their own for anything else.
 */
export function ChartOfAccountsPage() {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const { can } = useAuth()
  const chart = useChartOfAccounts()
  const [tab, setTab] = useState<AccountType>('asset')
  const [editing, setEditing] = useState<AccountRow | { group: string; type: AccountType } | null>(null)
  const [opening, setOpening] = useState<AccountRow | null>(null)
  const toast = useToast()
  const { confirm, element: confirmDialog } = useConfirm()
  const remove = useAccountAction((id: number) => accountActions.remove(id)())
  // Which money accounts already have an opening balance: it is said once, and never again.
  const options = usePaymentOptions()
  const opened = new Set((options.data?.money_accounts ?? []).filter((row) => row.has_opening_balance).map((row) => row.code))
  const canOpen = can('transactions.create_manual') && can('ledger.view_company_balance')

  if (chart.isPending) return <Loading />
  if (chart.isError) return <ErrorNotice error={chart.error} />
  const manage = can('accounts.manage')
  const sections = chart.data.meta.groups[tab] ?? []

  return (
    <>
      <PageHeader title={t('accounts.title')} subtitle={t('accounts.subtitle')} />

      {/* One bar across the top, the kind picked out in white — the way the client's books read. */}
      <div className="flex justify-center">
        <div className="flex flex-wrap justify-center gap-1 rounded-pill bg-linear-90/srgb from-blue to-blue-abyss p-1" role="tablist" aria-label={t('accounts.title')}>
          {TABS.map((type) => (
            <button
              key={type}
              type="button"
              role="tab"
              aria-selected={tab === type}
              onClick={() => setTab(type)}
              className={`cursor-pointer rounded-pill px-5 py-1.75 text-13 font-semibold transition-colors duration-150 ${tab === type ? 'bg-app-surface text-app-text' : 'bg-transparent text-white/85 hover:text-white'}`}
            >
              {t(`accounts.tabs.${type}`)}
            </button>
          ))}
        </div>
      </div>

      <Card padded={false} className="overflow-hidden">
        {sections.map((section) => {
          const rows = chart.data.data.filter((account) => account.type === tab && account.group === section.key)
          return (
            <section key={section.key} data-testid={`accounts-${section.key}`}>
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line bg-app-surface-2 px-4 py-2.5">
                <span className="flex items-center gap-2">
                  <strong className="text-14">{section.title}</strong>
                  <span title={section.help} aria-label={section.help} className="flex size-4.5 cursor-help items-center justify-center rounded-pill border border-app-line text-11 text-app-muted">
                    ?
                  </span>
                </span>
                {manage ? (
                  <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setEditing({ group: section.key, type: tab })}>
                    {t('accounts.addToSection')}
                  </button>
                ) : null}
              </div>

              {rows.length === 0 ? (
                <p className="m-0 px-4 py-3 text-13 text-app-muted">{t('accounts.emptySection', { section: section.title })}</p>
              ) : (
                <ul className="m-0 flex list-none flex-col p-0">
                  {rows.map((account) => (
                    <li key={account.id} className="grid gap-2 border-b border-app-line px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_auto] sm:items-start">
                      <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="flex flex-wrap items-baseline gap-1.5">
                          <strong className="font-medium">{account.name}</strong>
                          {account.entries > 0 ? <span className="font-display text-13 text-blue">( {bdt(account.balance)} )</span> : null}
                          {account.is_money ? <Badge tone="green">{t('accounts.money')}</Badge> : null}
                          {account.is_system ? <Badge tone="slate">{t('accounts.system')}</Badge> : null}
                        </span>
                        <span className="font-display text-11 text-app-muted">{account.code}</span>
                        <span className="text-11 text-app-muted">
                          {account.last_entry_on ? t('accounts.lastEntry', { date: date(account.last_entry_on) }) : t('accounts.neverUsed')}
                        </span>
                      </span>
                      <span className="text-12 text-app-muted">{account.description}</span>
                      {manage ? (
                        <span className="flex flex-wrap justify-end gap-1.5">
                          {/* Where the books start for an account that holds money. It can only ever be said once. */}
                          {account.is_money && canOpen && !opened.has(account.code) ? (
                            <button type="button" className={buttonClass('outline', 'sm', 'text-12')} onClick={() => setOpening(account)}>
                              {t('accounts.setOpening')}
                            </button>
                          ) : null}
                          {!account.is_system && account.entries === 0 ? (
                            <button
                              type="button"
                              aria-label={t('accounts.deleteAccount', { name: account.name })}
                              className={buttonClass('danger', 'sm', 'px-2.5 py-1 text-12')}
                              onClick={async () => {
                                if (!(await confirm(t('accounts.deleteConfirm', { name: account.name })))) return
                                remove.mutate(account.id, { onSuccess: () => toast(t('accounts.deleted')), onError: (error) => toast(error.message, 'error') })
                              }}
                            >
                              🗑
                            </button>
                          ) : null}
                          <button
                            type="button"
                            aria-label={t('accounts.editAccount', { name: account.name })}
                            className={buttonClass('outline', 'sm', 'px-2.5 py-1 text-12')}
                            onClick={() => setEditing(account)}
                          >
                            ✎
                          </button>
                        </span>
                      ) : null}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )
        })}
      </Card>

      <p className="m-0 text-right text-13 text-app-muted">
        {t(`accounts.tabs.${tab}`)} <strong className="font-display text-app-text">{bdt(chart.data.meta.totals[tab] ?? 0)}</strong>
      </p>

      {editing ? (
        <AccountDialog
          account={'id' in editing ? editing : null}
          start={'id' in editing ? null : editing}
          sections={chart.data.meta.groups}
          onClose={() => setEditing(null)}
        />
      ) : null}
      {opening ? <OpeningBalanceDialog account={opening} onClose={() => setOpening(null)} /> : null}
      {confirmDialog}
    </>
  )
}

/** Where the books start for one money account. It is said once; a wrong figure is corrected with a balance adjustment. */
function OpeningBalanceDialog({ account, onClose }: { account: AccountRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ amount: null as number | null, as_of: todayInDhaka(), note: '' })
  const save = usePaymentsMutation(() => paymentActions.openingBalance({ account: account.code, amount: form.amount ?? 0, as_of: form.as_of, note: form.note.trim() || null }))

  return (
    <Dialog open onClose={onClose} title={t('accounts.openingTitle', { name: account.name })}>
      <p className="m-0 text-13 text-app-muted">{t('accounts.openingNote')}</p>
      <NumberInput label={t('transactions.amount')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} />
      <TextInput label={t('payments.asOf')} type="date" max={todayInDhaka()} value={form.as_of} onChange={(as_of) => setForm({ ...form, as_of })} />
      <TextArea label={t('payments.note')} value={form.note} onChange={(note) => setForm({ ...form, note })} rows={2} />
      {save.error ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={form.amount === null || save.isPending}
          onClick={() => save.mutate(undefined, { onSuccess: () => { toast(t('payments.openingSaved')); onClose() } })}
        >
          {save.isPending ? t('common.saving') : t('payments.openingSubmit')}
        </button>
      </div>
    </Dialog>
  )
}

/**
 * Adding or rewording one account. The number and the description are behind a link, as they are on the books the
 * client keeps: most accounts need only a name and the section they belong in.
 */
function AccountDialog({
  account,
  start,
  sections,
  onClose,
}: {
  account: AccountRow | null
  start: { group: string; type: AccountType } | null
  sections: Record<AccountType, AccountSection[]>
  onClose: () => void
}) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({
    name: account?.name ?? '',
    type: (account?.type ?? start?.type ?? 'expense') as AccountType,
    group: account?.group ?? start?.group ?? '',
    description: account?.description ?? '',
    code: account?.code ?? '',
    is_money: account?.is_money ?? false,
  })
  const [showMore, setShowMore] = useState(false)
  const save = useAccountAction(account ? accountActions.update(account.id) : accountActions.create)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const system = account?.is_system ?? false
  const forType = sections[form.type] ?? []

  // Changing the kind takes the section with it: a liability can't stay under Operating Expense.
  const setType = (type: AccountType) => setForm({ ...form, type, group: (sections[type] ?? [])[0]?.key ?? '' })

  return (
    <Dialog open onClose={onClose} title={account ? t('accounts.editTitle') : t('accounts.addTitle')}>
      <SelectInput
        label={t('accounts.type')}
        value={form.type}
        onChange={(type) => setType(type as AccountType)}
        options={ACCOUNT_TYPES.map((type) => ({ value: type, label: t(`accounts.tabs.${type}`) }))}
        disabled={system}
        error={fieldError('type')}
      />
      <SelectInput
        label={t('accounts.section')}
        value={form.group}
        onChange={(group) => setForm({ ...form, group })}
        options={forType.map((section) => ({ value: section.key, label: section.title }))}
        error={fieldError('group')}
      />
      <TextInput label={t('accounts.name')} value={form.name} onChange={(name) => setForm({ ...form, name })} error={fieldError('name')} />

      {showMore ? (
        <>
          <TextInput
            label={t('accounts.code')}
            value={form.code}
            onChange={(code) => setForm({ ...form, code })}
            disabled={system}
            hint={system ? t('accounts.systemNote') : t('accounts.codeHint')}
            error={fieldError('code')}
          />
          <TextArea label={t('accounts.description')} value={form.description} onChange={(description) => setForm({ ...form, description })} rows={2} error={fieldError('description')} />
        </>
      ) : (
        <button type="button" className="cursor-pointer self-center border-0 bg-transparent p-0 text-13 font-semibold text-blue" onClick={() => setShowMore(true)}>
          {t('accounts.editIdAndDescription')}
        </button>
      )}

      {/* A float somebody holds: counted inside the company balance, like the office cash and the bank. */}
      {form.type === 'asset' && !system ? (
        <Switch label={t('accounts.holdsMoney')} checked={form.is_money} onChange={(is_money) => setForm({ ...form, is_money })} hint={t('accounts.holdsMoneyHint')} />
      ) : null}
      {fieldError('is_money') ? <p className="text-13 text-red">{fieldError('is_money')}</p> : null}
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
              {
                name: form.name.trim(),
                type: form.type,
                group: form.group || null,
                description: form.description.trim() || null,
                code: form.code.trim() || null,
                is_money: form.type === 'asset' && form.is_money,
              },
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
