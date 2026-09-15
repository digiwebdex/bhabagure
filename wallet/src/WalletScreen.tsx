import { useTranslation } from 'react-i18next'

import { Breakdown } from './Breakdown'
import { CashForm } from './CashForm'
import { Deals } from './Deals'
import { History } from './History'
import { useFormat } from './lib/format'
import { actions, useSummary, type Me } from './lib/queries'
import { Notice, Shell } from './ui'

/**
 * The prototype's one screen (_design/Super Admin Wallet.dc.html): the balance and totals, cash in or out, the breakdown
 * by source, deals with advance and due, and the history — every figure from the wallet's own database.
 */
export function WalletScreen({ me, onSignedOut }: { me: Me; onSignedOut: () => void }) {
  const { t } = useTranslation()
  const { bdt, number, date, month } = useFormat()
  const summary = useSummary()
  const data = summary.data

  return (
    <Shell
      actions={
        <button
          type="button"
          className="cursor-pointer rounded-pill border border-wallet-input bg-transparent px-3.5 py-1.75 text-13 font-semibold text-wallet-muted"
          onClick={() => void actions.signOut().finally(onSignedOut)}
          title={me.email}
        >
          {t('signOut')}
        </button>
      }
    >
      <Notice tone="violet">{t('isolationNote')}</Notice>

      <section className="grid-auto-fit-210 grid gap-3.5" data-testid="wallet-kpis">
        <div className="flex flex-col gap-1.5 rounded-18 border border-wallet-violet/30 bg-gradient-to-br from-wallet-plum to-wallet-plum-deep p-5.5">
          <span className="text-13 text-wallet-lilac">{t('kpis.balance')}</span>
          <span className="font-display text-[clamp(30px,4.4vw,40px)] leading-none font-extrabold tracking-tight" data-testid="wallet-balance">
            {data ? bdt(data.balance) : '—'}
          </span>
          <span className="text-12 text-wallet-lilac">{data?.last_entry_at ? t('kpis.lastEntry', { date: date(data.last_entry_at) }) : t('kpis.noEntries')}</span>
        </div>
        {[
          [t('kpis.totalIn'), data ? bdt(data.total_in) : '—', 'text-wallet-in-soft', t('kpis.entries', { count: data?.count_in ?? 0, n: number(data?.count_in ?? 0) })],
          [t('kpis.totalOut'), data ? bdt(data.total_out) : '—', 'text-wallet-out-soft', t('kpis.entries', { count: data?.count_out ?? 0, n: number(data?.count_out ?? 0) })],
          [t('kpis.monthIn'), data ? bdt(data.month_in) : '—', 'text-wallet-text', month()],
        ].map(([label, value, color, note]) => (
          <div key={label} className="flex flex-col gap-1.5 rounded-18 border border-wallet-line bg-wallet-surface p-5.5">
            <span className="text-13 text-wallet-muted">{label}</span>
            <span className={`font-display text-26 font-extrabold tracking-tight ${color}`}>{value}</span>
            <span className="text-12 text-wallet-muted">{note}</span>
          </div>
        ))}
      </section>

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(300px,0.95fr)_minmax(0,1.35fr)]">
        <CashForm />
        <div className="flex min-w-0 flex-col gap-5">
          <Breakdown summary={data} error={summary.error} />
          <Deals dueTotal={data?.due_total ?? 0} />
          <History />
        </div>
      </div>
    </Shell>
  )
}
