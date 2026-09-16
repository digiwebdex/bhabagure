import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <main className="flex min-h-dvh items-center justify-center bg-app-nav px-4 py-10">
      <div className="flex w-full max-w-modal-sm flex-col gap-6">
        <img src="/brand/logo-wordmark-light.png" alt={t('shell.brand')} className="block h-auto w-full max-w-sidebar-logo" />
        <section className="flex flex-col gap-4.5 rounded-16 border border-app-line bg-app-surface p-6 text-app-text">
          <div className="flex flex-col gap-1">
            <h1 className="m-0 text-22 font-bold tracking-heading">{title}</h1>
            <p className="m-0 text-13.5 leading-1.55 text-app-muted">{subtitle}</p>
          </div>
          {children}
        </section>
      </div>
    </main>
  )
}
