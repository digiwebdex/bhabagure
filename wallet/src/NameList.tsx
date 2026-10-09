import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { DeleteIcon, EditIcon } from './icons'
import { useBookChange } from './lib/queries'
import { IconButton, Notice, inputClass } from './ui'

type Item = { id: number; name: string }

/**
 * A short list of names, each with Edit (renamed in place) and Delete (asked first): the businesses and sources, and the
 * saved references (docs/wallet-edits.md).
 */
export function NameList({
  items,
  rename,
  remove,
  text,
  testId,
}: {
  items: Item[]
  rename: (item: Item) => Promise<unknown>
  remove: (id: number) => Promise<unknown>
  text: { field: string; edit: (name: string) => string; delete: (name: string) => string; confirm: (name: string) => string }
  testId: string
}) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState<Item | null>(null)
  const [confirming, setConfirming] = useState<number | null>(null)
  const renaming = useBookChange(rename)
  const removing = useBookChange(remove)
  const error = renaming.error ?? removing.error
  const small = 'cursor-pointer rounded-8 border border-wallet-input bg-transparent px-2.5 py-1 text-12 font-semibold disabled:opacity-50'
  const saveName = () => {
    if (!editing || editing.name.trim().length < 2) return
    renaming.mutate({ id: editing.id, name: editing.name.trim() }, { onSuccess: () => setEditing(null) })
  }

  if (items.length === 0) return null

  return (
    <div className="flex flex-col gap-1.5">
      <ul className="m-0 flex list-none flex-col gap-1.5 p-0" data-testid={testId}>
        {items.map((item) => (
          <li key={item.id} className="flex flex-col gap-1.5 rounded-10 border border-wallet-line px-2.5 py-1.5">
            {editing?.id === item.id ? (
              // Not a <form>: this list sits inside the cash form, and Enter here must rename, not record cash.
              <span className="flex gap-1.5">
                <input
                  className={`${inputClass} py-1.5 text-13`}
                  value={editing.name}
                  onChange={(e) => setEditing({ id: item.id, name: e.target.value })}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault()
                      saveName()
                    } else if (event.key === 'Escape') {
                      event.stopPropagation()
                      setEditing(null)
                    }
                  }}
                  aria-label={text.field}
                  maxLength={120}
                  autoFocus
                />
                <button type="button" className={`${small} text-wallet-lilac`} disabled={editing.name.trim().length < 2 || renaming.isPending} onClick={saveName}>
                  {t('names.save')}
                </button>
                <button type="button" className={`${small} text-wallet-muted`} onClick={() => setEditing(null)}>
                  {t('names.cancel')}
                </button>
              </span>
            ) : (
              <span className="flex items-center justify-between gap-2">
                <span className="min-w-0 truncate text-13 text-wallet-text">{item.name}</span>
                <span className="flex gap-1.5">
                  <IconButton
                    label={text.edit(item.name)}
                    onClick={() => {
                      renaming.reset()
                      removing.reset()
                      setConfirming(null)
                      setEditing(item)
                    }}
                  >
                    <EditIcon />
                  </IconButton>
                  <IconButton
                    label={text.delete(item.name)}
                    tone="out"
                    onClick={() => {
                      renaming.reset()
                      removing.reset()
                      setEditing(null)
                      setConfirming(item.id)
                    }}
                  >
                    <DeleteIcon />
                  </IconButton>
                </span>
              </span>
            )}
            {confirming === item.id ? (
              <span role="alertdialog" aria-label={text.delete(item.name)} className="flex flex-wrap items-center justify-between gap-2 text-12 leading-1.5 text-wallet-muted">
                {text.confirm(item.name)}
                <span className="flex gap-1.5">
                  <button type="button" className={`${small} text-wallet-muted`} onClick={() => setConfirming(null)}>
                    {t('names.cancel')}
                  </button>
                  <button type="button" className={`${small} border-wallet-out-soft/50 text-wallet-out-soft`} disabled={removing.isPending} onClick={() => removing.mutate(item.id, { onSuccess: () => setConfirming(null) })}>
                    {t('names.delete')}
                  </button>
                </span>
              </span>
            ) : null}
          </li>
        ))}
      </ul>
      {error ? <Notice tone="out">{error.message}</Notice> : null}
    </div>
  )
}
