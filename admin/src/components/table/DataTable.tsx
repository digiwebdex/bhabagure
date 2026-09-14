import { useEffect, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../ui/button'
import { Dialog } from '../ui/feedback'

/**
 * The one table every admin list with row actions uses (docs/phase-5-admin-core.md §3.2). The actions column is a
 * sticky right-hand cell, as on the prototype's Bookings table, built so none of the eight measured failures return:
 *
 * F1 not sticky        `sticky right-0` on the actions <th>/<td>, directly inside the one horizontal scroll container
 *                      (no overflow, transform or contain in between).
 * F2 see-through       the cell is `bg-inherit` from a row that always paints an opaque token.
 * F3 short cell        a real <table>: the cell is full row height; padding lives on cells, never rows.
 * F4 white block       the row owns hover and selected colours; the cell inherits them in light and dark.
 * F5 short rows        `min-w-max` table with `border-separate border-spacing-0`; dividers are on cells, so tint,
 *                      divider and click target run the full scrolled width.
 * F6 clipped icon      the column is as wide as its content (`w-px whitespace-nowrap`); nothing fixes its width.
 * F7 no gutter         the right gutter is the cell's own right padding.
 * F8 unmarked edge     while columns are scrolled underneath, the cell shows an edge shadow; the header label is
 *                      aligned with the icon group; `z-1` keeps the cell above scrolled content.
 *
 * Below 640 px the cell stays sticky but holds one "⋯" button that opens the same actions in a sheet, so a phone's
 * 360 px scroll area isn't half covered by icons.
 */

export type RowActionTone = 'green' | 'purple' | 'blue' | 'amber' | 'muted' | 'red'

export type RowAction = {
  key: string
  icon: string
  /** Short name ("WhatsApp"); the accessible name adds the row ("WhatsApp — BH-2609-041"). */
  label: string
  tone: RowActionTone
  /** An external link: wa.me, sms:, mailto:. */
  href?: string
  /** An admin route. */
  to?: string
  newTab?: boolean
  onSelect?: () => void
  /** Present when the action can't be used here; shown as the tooltip, and in the phone sheet as text. */
  disabledReason?: string
}

export type Column<T> = {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  align?: 'left' | 'right'
  className?: string
}

type Props<T> = {
  label: string
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  /** Names the row in each action's accessible name and in the phone sheet's title: the reference, or the name. */
  rowLabel: (row: T) => string
  /** Leave out for a read-only list: no actions column is drawn. */
  actions?: (row: T) => RowAction[]
  onRowClick?: (row: T) => void
  isSelected?: (row: T) => boolean
  testId?: string
}

const toneClass: Record<RowActionTone, string> = {
  green: 'text-green hover:border-green hover:bg-green-tint',
  purple: 'text-purple hover:border-purple hover:bg-purple-tint',
  blue: 'text-blue hover:border-blue hover:bg-blue-tint',
  amber: 'text-amber hover:border-amber hover:bg-orange-tint',
  muted: 'text-app-muted hover:border-app-muted hover:bg-app-surface-2',
  red: 'text-red hover:border-red hover:bg-red-tint',
}

export function DataTable<T>({ label, columns, rows, rowKey, rowLabel, actions, onRowClick, isSelected, testId }: Props<T>) {
  const { t } = useTranslation()
  const scroller = useRef<HTMLDivElement>(null)
  const [edge, setEdge] = useState(false)
  const [sheetRow, setSheetRow] = useState<T | null>(null)

  // F8: mark the sticky edge only while some column is scrolled underneath the actions cell.
  useEffect(() => {
    const element = scroller.current
    if (!element) return
    const update = () => setEdge(element.scrollLeft + element.clientWidth < element.scrollWidth - 1)
    update()
    element.addEventListener('scroll', update, { passive: true })
    const observer = new ResizeObserver(update)
    observer.observe(element)
    if (element.firstElementChild) observer.observe(element.firstElementChild)
    return () => {
      element.removeEventListener('scroll', update)
      observer.disconnect()
    }
  }, [rows.length])

  const cellBase = 'border-b border-app-line px-4 py-3 align-middle group-last/row:border-b-0'

  return (
    <>
      <div ref={scroller} className="min-w-0 scroll-pr-56 overflow-x-auto" data-testid={testId}>
        <table aria-label={label} data-edge={edge} className="group/table w-full min-w-max border-separate border-spacing-0 text-13">
          <thead>
            <tr className="bg-app-surface">
              {columns.map((column) => (
                <th key={column.key} scope="col" className={`border-b border-app-line px-4 py-2.5 font-display text-12 font-semibold tracking-eyebrow text-app-muted uppercase ${column.align === 'right' ? 'text-right' : 'text-left'}`}>
                  {column.header}
                </th>
              ))}
              {actions ? (
                <th scope="col" data-sticky-actions className="sticky right-0 z-1 w-px border-b border-app-line bg-app-surface py-2.5 pr-4.5 pl-1.5 text-right font-display text-12 font-semibold tracking-eyebrow whitespace-nowrap text-app-muted uppercase group-data-[edge=true]/table:shadow-sticky-edge">
                  {t('table.actions')}
                </th>
              ) : null}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const selected = isSelected?.(row) ?? false
              const rowActions = actions?.(row) ?? []
              const name = rowLabel(row)
              return (
                <tr
                  key={rowKey(row)}
                  data-selected={selected}
                  data-testid="table-row"
                  onClick={onRowClick ? (event) => rowClick(event, () => onRowClick(row)) : undefined}
                  className={`group/row bg-app-surface data-[selected=false]:hover:bg-app-surface-2 data-[selected=true]:bg-app-selected ${onRowClick ? 'cursor-pointer' : ''}`}
                >
                  {columns.map((column) => (
                    <td key={column.key} className={`${cellBase} ${column.align === 'right' ? 'text-right' : ''} ${column.className ?? ''}`}>
                      {column.cell(row)}
                    </td>
                  ))}
                  {actions ? (
                    <td data-sticky-actions className={`sticky right-0 z-1 w-px bg-inherit py-2 pr-4.5 pl-1.5 whitespace-nowrap ${cellBase.replace('px-4 py-3 ', '')} group-data-[edge=true]/table:shadow-sticky-edge`}>
                      <div className="hidden items-center justify-end gap-0.75 sm:flex">
                        {rowActions.map((action) => (
                          <ActionIcon key={action.key} action={action} rowName={name} />
                        ))}
                      </div>
                      <div className="flex justify-end sm:hidden">
                        <button
                          type="button"
                          onClick={() => setSheetRow(row)}
                          aria-label={t('table.actionsFor', { name })}
                          aria-haspopup="dialog"
                          className="flex size-8 cursor-pointer items-center justify-center rounded-8 border border-app-line bg-transparent text-16 text-app-text hover:bg-app-surface-2"
                        >
                          ⋯
                        </button>
                      </div>
                    </td>
                  ) : null}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <Dialog open={sheetRow !== null} onClose={() => setSheetRow(null)} title={sheetRow ? t('table.actionsFor', { name: rowLabel(sheetRow) }) : ''}>
        {sheetRow ? (
          <ul className="m-0 flex list-none flex-col gap-2 p-0">
            {(actions?.(sheetRow) ?? []).map((action) => (
              <li key={action.key}>
                <SheetAction action={action} onDone={() => setSheetRow(null)} />
              </li>
            ))}
          </ul>
        ) : null}
      </Dialog>
    </>
  )
}

/** A row click never swallows a click meant for a link, button or form control inside the row. */
function rowClick(event: MouseEvent<HTMLTableRowElement>, open: () => void) {
  if ((event.target as HTMLElement).closest('a, button, input, select, textarea, label, [data-sticky-actions]')) return
  if (window.getSelection()?.toString()) return // selecting text isn't a click
  open()
}

function ActionIcon({ action, rowName }: { action: RowAction; rowName: string }) {
  const name = `${action.label} — ${rowName}`
  const className = `flex size-6 shrink-0 items-center justify-center rounded-7 border border-app-line bg-transparent text-12 no-underline transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2 ${toneClass[action.tone]}`

  if (action.disabledReason) {
    // A real button, disabled: the column keeps its width and the reason is the tooltip.
    return (
      <button type="button" disabled title={`${action.label}: ${action.disabledReason}`} aria-label={`${name} (${action.disabledReason})`} className={`${className} cursor-not-allowed opacity-40 hover:border-app-line hover:bg-transparent`}>
        {action.icon}
      </button>
    )
  }
  if (action.to) {
    return (
      <Link to={action.to} title={action.label} aria-label={name} className={className}>
        {action.icon}
      </Link>
    )
  }
  if (action.href) {
    return (
      <a href={action.href} target={action.newTab ? '_blank' : undefined} rel={action.newTab ? 'noopener noreferrer' : undefined} title={action.label} aria-label={name} className={className}>
        {action.icon}
      </a>
    )
  }
  return (
    <button type="button" onClick={action.onSelect} title={action.label} aria-label={name} className={`${className} cursor-pointer`}>
      {action.icon}
    </button>
  )
}

function SheetAction({ action, onDone }: { action: RowAction; onDone: () => void }) {
  const content = (
    <>
      <span aria-hidden className="w-5 text-center">
        {action.icon}
      </span>
      <span className="flex min-w-0 flex-1 flex-col text-left">
        <span>{action.label}</span>
        {action.disabledReason ? <span className="text-12 font-normal text-app-muted">{action.disabledReason}</span> : null}
      </span>
    </>
  )
  const className = buttonClass('outline', 'md', 'w-full justify-start gap-3')
  const select = (event: KeyboardEvent | MouseEvent) => {
    if (action.onSelect) {
      event.preventDefault()
      onDone()
      action.onSelect()
    } else {
      onDone()
    }
  }

  if (action.disabledReason) {
    return (
      <button type="button" disabled className={`${className} opacity-60`}>
        {content}
      </button>
    )
  }
  if (action.to) {
    return (
      <Link to={action.to} className={className} onClick={onDone}>
        {content}
      </Link>
    )
  }
  if (action.href) {
    return (
      <a href={action.href} target={action.newTab ? '_blank' : undefined} rel={action.newTab ? 'noopener noreferrer' : undefined} className={className} onClick={onDone}>
        {content}
      </a>
    )
  }
  return (
    <button type="button" className={className} onClick={select}>
      {content}
    </button>
  )
}
