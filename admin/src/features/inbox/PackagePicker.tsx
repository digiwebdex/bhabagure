import { formatBdt } from '@bhabaghure/format'
import { packagePerPerson } from '@bhabaghure/pricing'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice } from '../../components/ui/feedback'
import { Loading } from '../../components/ui/layout'
import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import type { BookingFormOptions } from '../bookings/api'

const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')

/**
 * "Send package details" (the design's quick reply): a published package's name, its price per person for two and its
 * page on the website, put into the reply in Bangla for staff to adjust before sending. Priced with @bhabaghure/pricing
 * as the website's cards are.
 */
export function PackagePicker({ onPick, onClose }: { onPick: (text: string) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [search, setSearch] = useState('')
  const options = useQuery({ queryKey: ['booking-options'], queryFn: ({ signal }) => api.get<Data<BookingFormOptions>>('admin/bookings/options', signal).then((r) => r.data) })

  const price = (pkg: BookingFormOptions['packages'][number]) =>
    packagePerPerson({ listPrice: pkg.list_price, priceGrid: pkg.price_grid, fixedPrice: pkg.fixed_price }, 2, options.data?.config.slabs ?? [])
  const text = (pkg: BookingFormOptions['packages'][number]) =>
    [pkg.title_bn || pkg.title_en, `জনপ্রতি ${formatBdt(price(pkg), 'bn')} থেকে (২ জন)`, SITE_URL ? `${SITE_URL}/packages/${pkg.slug}` : null].filter(Boolean).join('\n')

  const shown = (options.data?.packages ?? []).filter((pkg) => `${pkg.title_en} ${pkg.title_bn ?? ''}`.toLowerCase().includes(search.trim().toLowerCase()))

  return (
    <Dialog open onClose={onClose} title={t('inbox.sendPackage')}>
      <input type="search" autoFocus className={controlClass()} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('inbox.findPackage')} aria-label={t('inbox.findPackage')} />
      {options.isPending ? <Loading /> : options.isError ? <ErrorNotice error={options.error} /> : null}
      <ul className="m-0 flex max-h-[60dvh] list-none flex-col overflow-y-auto p-0">
        {shown.map((pkg) => (
          <li key={pkg.slug}>
            <button
              type="button"
              onClick={() => {
                onPick(text(pkg))
                onClose()
              }}
              className="flex w-full cursor-pointer items-center justify-between gap-3 border-b border-app-line px-1 py-2.5 text-left text-14 hover:bg-app-surface-2"
            >
              <span className="min-w-0 truncate">{pkg.title_en || pkg.title_bn}</span>
              <span className="shrink-0 font-display text-13 text-app-muted">{bdt(price(pkg))}</span>
            </button>
          </li>
        ))}
      </ul>
    </Dialog>
  )
}
