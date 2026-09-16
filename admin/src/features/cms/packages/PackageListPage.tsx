import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice, useToast } from '../../../components/ui/feedback'
import { controlClass } from '../../../components/ui/controls'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader, ReorderButtons, StatusBadge } from '../../../components/ui/layout'
import { move } from '../../../lib/move'
import type { PackageStatus, PackageSummary } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'
import { MediaThumb } from '../media/media'
import { packageActions, useDestinations, usePackageMutation, usePackages } from './api'
import { DestinationsCard } from './DestinationsCard'

export function PackageListPage() {
  const { t } = useTranslation()
  const { bdt, number } = useFormat()
  const toast = useToast()
  const [status, setStatus] = useState<PackageStatus | 'all'>('all')
  const [destination, setDestination] = useState('')
  const [search, setSearch] = useState('')
  const list = usePackages(status, destination, search)
  const destinations = useDestinations()
  const reorder = usePackageMutation(packageActions.reorder)

  // Reordering only makes sense on the full, unfiltered list — the order is the website's card order.
  const canReorder = status === 'all' && !destination && !search

  const onMove = (rows: PackageSummary[], from: number, to: number) => {
    reorder.mutate(move(rows, from, to).map((row) => row.id), { onSuccess: () => toast(t('packages.orderSaved')) })
  }

  return (
    <>
      <PageHeader
        title={t('packages.title')}
        subtitle={t('packages.subtitle')}
        actions={
          <Link to="/packages/new" className={buttonClass('cta')}>
            {t('packages.new')}
          </Link>
        }
      />

      <div className="flex flex-col gap-2.5">
        <Chips
          label={t('common.status')}
          value={status}
          onChange={setStatus}
          options={[
            { value: 'all', label: t('common.all') },
            { value: 'published', label: t('status.published') },
            { value: 'draft', label: t('status.draft') },
            { value: 'archived', label: t('status.archived') },
          ]}
        />
        {destinations.data ? (
          <Chips
            label={t('packages.destination')}
            value={destination}
            onChange={setDestination}
            options={[{ value: '', label: t('packages.allDestinations') }, ...destinations.data.data.map((d) => ({ value: d.slug, label: d.name_en || d.name_bn }))]}
          />
        ) : null}
      </div>

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <Card padded={false} className="overflow-x-auto">
          <div className="border-b border-app-line p-3.5">
            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('packages.search')} aria-label={t('packages.search')} className={controlClass()} />
          </div>
          {list.isPending ? <Loading /> : list.isError ? <div className="p-4"><ErrorNotice error={list.error} /></div> : list.data.data.length === 0 ? (
            <EmptyState title={t('packages.empty')} action={<Link to="/packages/new" className={buttonClass('primary', 'sm')}>{t('packages.new')}</Link>} />
          ) : (
            <ul className="m-0 list-none p-0">
              {list.data.data.map((pkg, index, rows) => (
                <li key={pkg.id} className="flex items-center gap-3 border-b border-app-line px-4 py-3 last:border-b-0 hover:bg-app-surface-2">
                  {canReorder ? <ReorderButtons index={index} count={rows.length} label={pkg.title_en} onMove={(from, to) => onMove(rows, from, to)} /> : null}
                  <MediaThumb media={pkg.cover} className="size-14" />
                  <Link to={`/packages/${pkg.id}`} className="flex min-w-0 flex-1 flex-col gap-0.5 text-app-text hover:text-app-text">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-display text-12 text-app-muted">{pkg.code}</span>
                      {pkg.destination ? <span className="text-12 font-semibold text-blue">{pkg.destination.name_en || pkg.destination.name_bn}</span> : null}
                    </span>
                    <span className="truncate text-14 font-medium">{pkg.title_en || pkg.title_bn}</span>
                    <span className="flex flex-wrap items-center gap-2 text-12 text-app-muted">
                      {t('packages.durationShort', { days: number(pkg.duration_days), nights: number(pkg.duration_nights ?? 0) })}
                      <span className="font-display font-semibold text-app-text">{bdt(pkg.sale_price ?? pkg.regular_price)}</span>
                      {pkg.sale_price !== null ? <s>{bdt(pkg.regular_price)}</s> : null}
                    </span>
                  </Link>
                  <span className="flex shrink-0 flex-col items-end gap-1">
                    <StatusBadge status={pkg.status} />
                    {pkg.missing_bangla ? <Badge tone="orange">{t('packages.missingBangla')}</Badge> : null}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <DestinationsCard />
      </div>
    </>
  )
}
