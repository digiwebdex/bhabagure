import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** docs/phase-9-accounts.md: the chart of accounts, the journal behind every figure, and the two reports. */
export const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'] as const
export type AccountType = (typeof ACCOUNT_TYPES)[number]

export type AccountRow = {
  id: number
  code: string
  name: string
  type: AccountType
  description: string | null
  /** Named by the software and posted to by the ledger: it can be reworded, never removed. */
  is_system: boolean
  /** Cash, bank or a wallet: the company balance counts these, and journal entries may not touch them. */
  is_money: boolean
  debit: number
  credit: number
  /** In the account's own direction: assets and expenses rise on debits, the rest on credits. */
  balance: number
  entries: number
}

export type JournalLine = { account_id: number; code: string; account: string; debit: number; credit: number }

export type JournalRow = {
  id: number
  date: string
  description: string
  manual: boolean
  source: string | null
  booking_id: number | null
  reverses: number | null
  reversed: boolean
  staff: string | null
  lines: JournalLine[]
  total: number
  actions: { reverse: boolean }
}

export type AccountTransactionRow = {
  entry_id: number
  date: string
  description: string
  source: string | null
  booking_id: number | null
  is_reversal: boolean
  debit: number
  credit: number
  balance: number
}

type Chart = { data: AccountRow[]; meta: { types: AccountType[]; totals: Record<AccountType, number> } }
type Journal = { data: JournalRow[]; meta: { current_page: number; last_page: number; total: number } }
type AccountTransactions = { data: AccountTransactionRow[]; meta: { account: { id: number; code: string; name: string; type: AccountType }; opening: number; closing: number; debit: number; credit: number } }
type LedgerRow = { id: number; code: string; name: string; type: AccountType; debit: number; credit: number; balance: number }
type GeneralLedger = { data: LedgerRow[]; meta: { totals: { debit: number; credit: number }; types: AccountType[] } }

export function useChartOfAccounts() {
  return useQuery({ queryKey: ['accounts'], queryFn: ({ signal }) => api.get<Chart>('admin/accounts', signal) })
}

export function useJournal(filters: { from: string; to: string; kind: string; account_id: string; search: string; page: number }) {
  const params = new URLSearchParams({ page: String(filters.page) })
  for (const [key, value] of Object.entries(filters)) if (key !== 'page' && value) params.set(key, String(value))
  return useQuery({
    queryKey: ['journal-entries', params.toString()],
    queryFn: ({ signal }) => api.get<Journal>(`admin/journal-entries?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useAccountTransactions(filters: { account_id: number | null; from: string; to: string }) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) if (value) params.set(key, String(value))
  return useQuery({
    queryKey: ['account-transactions', params.toString()],
    queryFn: ({ signal }) => api.get<AccountTransactions>(`admin/reports/account-transactions?${params}`, signal),
    enabled: filters.account_id !== null,
    placeholderData: (previous) => previous,
  })
}

export function useGeneralLedger(filters: { from: string; to: string }) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) if (value) params.set(key, value)
  return useQuery({
    queryKey: ['general-ledger', params.toString()],
    queryFn: ({ signal }) => api.get<GeneralLedger>(`admin/reports/general-ledger?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

/** Every change answers with the chart or the journal, so the screens refresh themselves. */
export function useAccountAction<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['accounts'] })
      void client.invalidateQueries({ queryKey: ['journal-entries'] })
      void client.invalidateQueries({ queryKey: ['account-transactions'] })
      void client.invalidateQueries({ queryKey: ['general-ledger'] })
    },
  })
}

export type AccountInput = { name: string; type: AccountType; description: string | null; code: string | null; is_money: boolean }
export type JournalInput = { entry_date: string; description: string; lines: { account_id: number | null; debit: number | null; credit: number | null }[] }

export const accountActions = {
  create: (input: AccountInput) => api.post<Data<AccountRow>>('admin/accounts', input),
  update: (id: number) => (input: AccountInput) => api.put<Data<AccountRow>>(`admin/accounts/${id}`, input),
  remove: (id: number) => () => api.delete<Data<{ deleted: boolean }>>(`admin/accounts/${id}`),
  post: (input: JournalInput) => api.post<Data<{ id: number }>>('admin/journal-entries', input),
  reverse: ({ id, reason }: { id: number; reason: string }) => api.post<Data<{ id: number }>>(`admin/journal-entries/${id}/reverse`, { reason }),
}

/** The CSV a report downloads, fetched with the session's token like every other admin call. */
export function reportCsvUrl(path: string, params: Record<string, string | number | null>): string {
  const query = new URLSearchParams({ format: 'csv' })
  for (const [key, value] of Object.entries(params)) if (value) query.set(key, String(value))
  return `${path}?${query}`
}
