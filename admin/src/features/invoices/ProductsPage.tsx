import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, Switch, TextInput } from '../../components/ui/fields'
import { Badge, Card, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { useCatalogue, useSaveProduct, type CatalogueItem } from './products'

/**
 * Invoices → Products (docs/invoice-items.md): the office's own products an invoice's Add New Item offers beside the
 * packages. Rename, reprice or hide one; a hidden product stays on the invoices that used it. Packages are edited in
 * Packages.
 */
export function ProductsPage() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const { can } = useAuth()
  const products = useCatalogue(true)
  const [editing, setEditing] = useState<CatalogueItem | 'new' | null>(null)

  return (
    <>
      <PageHeader
        title={t('invoices.items.productsTitle')}
        subtitle={t('invoices.items.productsSubtitle')}
        actions={
          <>
            <Link to="/invoices" className={buttonClass('outline', 'sm')}>
              {t('invoices.items.backToInvoices')}
            </Link>
            {can('invoices.manage') ? (
              <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setEditing('new')}>
                + {t('invoices.items.addProduct')}
              </button>
            ) : null}
          </>
        }
      />
      <Card>
        {products.isPending ? (
          <Loading />
        ) : products.isError ? (
          <ErrorNotice error={products.error} />
        ) : products.data.data.length === 0 ? (
          <EmptyState title={t('invoices.items.noProducts')} note={t('invoices.items.noProductsNote')} />
        ) : (
          <ul className="m-0 flex list-none flex-col p-0" data-testid="products">
            {products.data.data.map((product) => (
              <li key={product.key} className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line py-3 last:border-b-0">
                <span className="flex min-w-0 flex-col">
                  <span className="flex items-center gap-2 text-14 font-semibold">
                    {product.name}
                    {product.is_active ? null : <Badge tone="slate">{t('invoices.items.hidden')}</Badge>}
                  </span>
                  {product.description ? <span className="text-12 text-app-muted">{product.description}</span> : null}
                </span>
                <span className="flex items-center gap-3">
                  <span className="font-display text-14">{bdt(product.unit_price)}</span>
                  {can('invoices.manage') ? (
                    <button type="button" className={buttonClass('ghost', 'sm')} onClick={() => setEditing(product)} aria-label={t('invoices.items.editNamed', { name: product.name })}>
                      {t('invoices.items.edit')}
                    </button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {editing ? <ProductDialog product={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </>
  )
}

function ProductDialog({ product, onClose }: { product: CatalogueItem | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const save = useSaveProduct()
  const [name, setName] = useState(product?.name ?? '')
  const [price, setPrice] = useState<number | null>(product?.unit_price ?? null)
  const [description, setDescription] = useState(product?.description ?? '')
  const [active, setActive] = useState(product?.is_active ?? true)
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  return (
    <Dialog open onClose={onClose} title={product ? t('invoices.items.editProduct') : t('invoices.items.addProduct')}>
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (save.isPending || price === null) return
          save.mutate(
            { id: product?.id, name, description: description.trim() || null, unit_price: price, is_active: active },
            {
              onSuccess: () => {
                toast(t('common.saved'))
                onClose()
              },
            },
          )
        }}
      >
        <TextInput label={t('invoices.items.name')} value={name} onChange={setName} error={error('name')} autoFocus />
        <NumberInput label={t('invoices.items.price')} value={price} onChange={setPrice} error={error('unit_price')} />
        <TextInput label={t('invoices.items.description')} value={description} onChange={setDescription} error={error('description')} />
        {product ? <Switch label={t('invoices.items.offered')} hint={t('invoices.items.offeredHint')} checked={active} onChange={setActive} /> : null}
        {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('primary')} aria-disabled={save.isPending || price === null || name.trim() === ''}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
