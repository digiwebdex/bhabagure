import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../../components/ui/button'
import { ErrorNotice } from '../../../components/ui/feedback'
import { Badge, Card, EmptyState, Loading, PageHeader, StatusBadge } from '../../../components/ui/layout'
import { MediaThumb } from '../media/media'
import { useTourPages } from './api'

/** Website → Tour landing pages (docs/tour-landing-pages.md): one page per group tour, made and published here. */
export function TourPagesListPage() {
  const { t } = useTranslation()
  const list = useTourPages()

  return (
    <>
      <PageHeader
        title={t('tourPages.title')}
        subtitle={t('tourPages.subtitle')}
        actions={
          <Link to="/tour-pages/new" className={buttonClass('cta')}>
            {t('tourPages.new')}
          </Link>
        }
      />
      <Card padded={false}>
        {list.isPending ? <Loading /> : list.isError ? <div className="p-4"><ErrorNotice error={list.error} /></div> : list.data.data.length === 0 ? (
          <EmptyState title={t('tourPages.empty')} action={<Link to="/tour-pages/new" className={buttonClass('primary', 'sm')}>{t('tourPages.new')}</Link>} />
        ) : (
          <ul className="m-0 list-none p-0">
            {list.data.data.map((page) => (
              <li key={page.id} className="flex items-center gap-3 border-b border-app-line px-4 py-3 last:border-b-0 hover:bg-app-surface-2">
                <MediaThumb media={page.hero} className="size-14" />
                <Link to={`/tour-pages/${page.id}`} className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate font-semibold">{page.package_title ?? page.slug}</span>
                  <span className="truncate text-12 text-app-muted">/tours/{page.slug}</span>
                </Link>
                {page.promote_on_home ? <Badge tone="orange">{t('tourPages.onHome')}</Badge> : null}
                {page.package_status !== 'published' ? <Badge tone="red">{t('tourPages.packageNotLive')}</Badge> : null}
                <StatusBadge status={page.status} />
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
