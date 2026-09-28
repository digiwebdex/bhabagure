import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { NumberInput, TextInput } from '../../components/ui/fields'
import { Badge } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { useCatalogue, useSaveProduct, type CatalogueItem } from './products'

export type PickedItem = { title: string; detail: string | null; unit_price: number }

/**
 * "Add New Item" (docs/invoice-items.md, as in the office's old software): the packages and the office's own products
 * to pick from, each with its price; a name that isn't on the list is added only for this invoice, or saved as a new
 * product with its price for next time.
 */
export function ItemPicker({ onPick }: { onPick: (item: PickedItem) => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const catalogue = useCatalogue()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [saving, setSaving] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  const close = () => {
    setOpen(false)
    setQuery('')
    setSaving(false)
  }
  const pick = (item: PickedItem) => {
    onPick(item)
    close()
  }

  // Clicking elsewhere or Escape closes it.
  useEffect(() => {
    if (!open) return
    const onDown = (event: MouseEvent) => box.current && !box.current.contains(event.target as Node) && close()
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && close()
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  })

  const typed = query.trim()
  const needle = typed.toLowerCase()
  const items = (catalogue.data?.data ?? []).filter((item) => `${item.name} ${item.description ?? ''}`.toLowerCase().includes(needle))
  const exact = (catalogue.data?.data ?? []).some((item) => item.name.trim().toLowerCase() === needle)
  const groups = [
    { key: 'products', label: t('invoices.items.products'), items: items.filter((item) => item.kind === 'product') },
    { key: 'packages', label: t('invoices.items.packages'), items: items.filter((item) => item.kind === 'package') },
  ].filter((group) => group.items.length > 0)

  return (
    <div ref={box} className="relative self-start" data-testid="item-picker">
      <button type="button" className={buttonClass('outline', 'sm')} onClick={() => (open ? close() : setOpen(true))} aria-expanded={open}>
        + {t('invoices.items.addNew')}
      </button>
      {open ? (
        <div className="absolute top-full left-0 z-30 mt-1.5 flex w-[min(92vw,30rem)] flex-col gap-2 rounded-12 border border-app-line bg-app-surface p-2.5 shadow-lg">
          <input
            type="search"
            autoFocus
            className={controlClass()}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setSaving(false)
            }}
            placeholder={t('invoices.items.search')}
            aria-label={t('invoices.items.search')}
          />
          {typed !== '' && !exact ? (
            saving ? (
              <NewProductForm name={typed} onSaved={pick} onCancel={() => setSaving(false)} />
            ) : (
              <div className="flex flex-col gap-1" data-testid="item-new-options">
                <button type="button" className={buttonClass('ghost', 'sm', 'justify-start text-left')} onClick={() => pick({ title: typed, detail: null, unit_price: 0 })}>
                  {t('invoices.items.onlyThis', { name: typed })}
                </button>
                <button type="button" className={buttonClass('ghost', 'sm', 'justify-start text-left')} onClick={() => setSaving(true)}>
                  {t('invoices.items.asProduct', { name: typed })}
                </button>
              </div>
            )
          ) : null}
          <div className="max-h-72 overflow-y-auto" role="listbox" aria-label={t('invoices.items.list')}>
            {catalogue.isPending ? <p className="m-0 p-2 text-13 text-app-muted">{t('common.loading')}</p> : null}
            {catalogue.isError ? <ErrorNotice error={catalogue.error} /> : null}
            {catalogue.data && groups.length === 0 ? <p className="m-0 p-2 text-13 text-app-muted">{t('invoices.items.none')}</p> : null}
            {groups.map((group) => (
              <div key={group.key} className="flex flex-col">
                <span className="px-2 pt-2 pb-1 font-display text-11 tracking-eyebrow text-app-muted uppercase">{group.label}</span>
                {group.items.map((item) => (
                  <Option key={item.key} item={item} price={bdt(item.unit_price)} onPick={() => pick({ title: item.name, detail: item.description, unit_price: item.unit_price })} />
                ))}
              </div>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  )
}

function Option({ item, price, onPick }: { item: CatalogueItem; price: string; onPick: () => void }) {
  const { t } = useTranslation()
  return (
    <button type="button" role="option" aria-selected={false} onClick={onPick} className="flex w-full cursor-pointer items-center justify-between gap-3 rounded-8 px-2 py-2 text-left hover:bg-app-surface-2">
      <span className="flex min-w-0 flex-col">
        <span className="flex items-center gap-1.5 text-14">
          <span className="truncate">{item.name}</span>
          {item.draft ? <Badge tone="slate">{t('invoices.items.draft')}</Badge> : null}
        </span>
        {item.description ? <span className="truncate text-12 text-app-muted">{item.description}</span> : null}
      </span>
      <span className="shrink-0 font-display text-13">{price}</span>
    </button>
  )
}

/** "as a new product": its price and a short description, saved to the list and put on this invoice. */
function NewProductForm({ name, onSaved, onCancel }: { name: string; onSaved: (item: PickedItem) => void; onCancel: () => void }) {
  const { t } = useTranslation()
  const save = useSaveProduct()
  const [price, setPrice] = useState<number | null>(null)
  const [description, setDescription] = useState('')
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  return (
    <div className="flex flex-col gap-2 rounded-10 border border-blue p-2.5" data-testid="new-product">
      <strong className="text-13">{t('invoices.items.newProduct', { name })}</strong>
      <NumberInput label={t('invoices.items.price')} value={price} onChange={setPrice} error={error('unit_price')} autoFocus />
      <TextInput label={t('invoices.items.description')} value={description} onChange={setDescription} error={error('description')} />
      {error('name') ? <p className="m-0 text-12 text-red">{error('name')}</p> : null}
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex gap-2">
        <button
          type="button"
          className={buttonClass('primary', 'sm')}
          aria-disabled={save.isPending || price === null}
          onClick={() => {
            if (save.isPending || price === null) return
            save.mutate(
              { name, description: description.trim() || null, unit_price: price },
              { onSuccess: (response) => onSaved({ title: response.data.name, detail: response.data.description, unit_price: response.data.unit_price }) },
            )
          }}
        >
          {save.isPending ? t('common.saving') : t('invoices.items.saveAndAdd')}
        </button>
        <button type="button" className={buttonClass('ghost', 'sm')} onClick={onCancel}>
          {t('common.cancel')}
        </button>
      </div>
    </div>
  )
}
