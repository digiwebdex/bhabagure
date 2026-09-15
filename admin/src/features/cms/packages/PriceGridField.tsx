import { useTranslation } from 'react-i18next'

import { defaultHotelCategory, GRID_TIERS, gridCategories, gridRate, HOTEL_CATEGORIES, type HotelCategory, type PriceGrid } from '@bhabaghure/pricing'

import { controlClass, parseNumber } from '../../../components/ui/controls'
import { useFormat } from '../../../lib/useFormat'

/**
 * Hotel-category × traveller prices (docs/phase-8-visa-quotes-pricing-downloads.md §4.D). A per-person price for 1, 2, 4,
 * 6 and 10 travellers in each category the package is sold in; a group between two sizes pays the smaller size's price.
 * A category is offered once it has the 1-traveller price. Filled in, the grid replaces the one price and the site-wide
 * group discounts for this package.
 */
export function PriceGridField({ value, onChange, error }: { value: PriceGrid | null; onChange: (grid: PriceGrid | null) => void; error: (field: string) => string | undefined }) {
  const { t } = useTranslation()
  const { bdt, number } = useFormat()
  const offered = gridCategories(value)

  const setCell = (category: HotelCategory, tier: number, text: string) => {
    const parsed = parseNumber(text)
    const row = { ...(value?.[category] ?? {}) }
    if (parsed === null || parsed === undefined || Number.isNaN(parsed)) delete row[`${tier}` as keyof typeof row]
    else row[`${tier}` as keyof typeof row] = Math.round(parsed)
    const next: PriceGrid = { ...(value ?? {}), [category]: row }
    if (Object.keys(row).length === 0) delete next[category]
    onChange(Object.keys(next).length === 0 ? null : next)
  }

  return (
    <fieldset className="m-0 flex min-w-0 flex-col gap-2 rounded-12 border border-app-line p-3.5" data-testid="price-grid">
      <legend className="px-1 text-14 font-semibold">{t('grid.title')}</legend>
      <p className="m-0 text-12 text-app-muted">{t('grid.note')}</p>
      <div className="overflow-x-auto">
        <table className="w-full min-w-120 border-collapse text-13">
          <thead>
            <tr>
              <th className="py-1.5 pr-2 text-left font-semibold text-app-muted">{t('grid.hotelCategory')}</th>
              {GRID_TIERS.map((tier, index) => (
                <th key={tier} scope="col" className="px-1 py-1.5 text-left font-semibold text-app-muted">
                  {index === GRID_TIERS.length - 1 ? t('grid.tierPlus', { n: number(tier) }) : t('grid.tier', { count: tier, n: number(tier) })}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {HOTEL_CATEGORIES.map((category) => (
              <tr key={category} className="border-t border-app-line">
                <th scope="row" className="py-2 pr-2 text-left font-medium whitespace-nowrap">
                  {t(`grid.categories.${category}`)}
                  {offered.includes(category) ? null : <span className="block text-11 font-normal text-app-muted">{t('grid.notOffered')}</span>}
                </th>
                {GRID_TIERS.map((tier) => {
                  const cell = value?.[category]?.[`${tier}` as '1']
                  return (
                    <td key={tier} className="px-1 py-2">
                      <input
                        inputMode="numeric"
                        aria-label={t('grid.cellLabel', { category: t(`grid.categories.${category}`), count: tier, n: number(tier) })}
                        value={cell === undefined ? '' : String(cell)}
                        onChange={(event) => setCell(category, tier, event.target.value)}
                        placeholder="—"
                        className={`${controlClass(!!error(`price_grid.${category}`))} w-full min-w-22`}
                      />
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {HOTEL_CATEGORIES.map((category) =>
        error(`price_grid.${category}`) ? (
          <p key={category} role="alert" className="m-0 text-12 text-red">
            {t(`grid.categories.${category}`)}: {error(`price_grid.${category}`)}
          </p>
        ) : null,
      )}
      {offered.length > 0 ? (
        <p className="m-0 text-12 text-app-muted" data-testid="price-grid-summary">
          {t('grid.summary', { categories: offered.map((category) => t(`grid.categories.${category}`)).join(', ') })}
          {/* What cards and lists show: the default category for two travellers (the API keeps the package price at it). */}
          {' · '}
          {t('grid.cardPrice', { amount: bdt(gridRate(value as PriceGrid, defaultHotelCategory(value) as HotelCategory, 2).perPerson) })}
        </p>
      ) : null}
    </fieldset>
  )
}
