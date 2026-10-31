import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, downloadDocument } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/**
 * api/app/Http/Controllers/Api/V1/Admin/TripProfitController.php (docs/trip-profit.md): each trip's costs, what was
 * collected and the profit, entered by hand — tied to no booking or invoice. Total cost and profit are the API's.
 */
export type TripType = 'group_tour' | 'custom_trip' | 'other'

export const TRIP_TYPES: TripType[] = ['group_tour', 'custom_trip', 'other']

export type Trip = {
  id: number
  title: string
  trip_type: TripType | null
  trip_date: string
  pax: number
  flight_cost: number
  land_package_cost: number
  other_cost: number
  total_cost: number
  total_collected_amount: number
  profit: number
  notes: string | null
  updated_at: string | null
}

export type TripSummary = { trips: number; pax: number; collected: number; cost: number; profit: number }

export type TripList = Paginated<Trip> & { summary: TripSummary; months: string[] }

/** 'all', a year 'YYYY' or a month 'YYYY-MM'. */
export type TripFilters = { period: string; search: string; type: TripType | ''; page: number }

export type TripInput = {
  title: string
  trip_type: TripType | null
  trip_date: string
  pax: number | null
  flight_cost: number | null
  land_package_cost: number | null
  other_cost: number | null
  total_collected_amount: number | null
  notes: string
}

const KEY = ['trip-profits'] as const

const query = ({ period, search, type, page }: Partial<TripFilters>) =>
  new URLSearchParams({ period: period ?? 'all', ...(search?.trim() ? { search: search.trim() } : {}), ...(type ? { type } : {}), ...(page && page > 1 ? { page: String(page) } : {}) })

export const useTrips = (filters: TripFilters) =>
  useQuery({
    queryKey: [...KEY, filters],
    queryFn: ({ signal }) => api.get<TripList>(`admin/trip-profits?${query(filters)}`, signal),
    placeholderData: keepPreviousData,
  })

export function useSaveTrip() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id?: number; input: TripInput }) =>
      id ? api.put<Data<Trip>>(`admin/trip-profits/${id}`, input) : api.post<Data<Trip>>('admin/trip-profits', input),
    onSuccess: () => void client.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteTrip() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.delete(`admin/trip-profits/${id}`),
    onSuccess: () => void client.invalidateQueries({ queryKey: KEY }),
  })
}

/** The filtered list (every page) as CSV for Excel, with a totals row. */
export const downloadTrips = (filters: Pick<TripFilters, 'period' | 'search' | 'type'>) =>
  downloadDocument(`admin/trip-profits/export?${query(filters)}`, `bhabaghure-trip-profit-${filters.period}.csv`)

/** What the form shows while typing, worked out in whole paisa as the API does; the API's figures are the saved ones. */
export function tripTotals(input: Pick<TripInput, 'flight_cost' | 'land_package_cost' | 'other_cost' | 'total_collected_amount'>) {
  const paisa = (value: number | null) => Math.round((value ?? 0) * 100)
  const cost = paisa(input.flight_cost) + paisa(input.land_package_cost) + paisa(input.other_cost)
  return { cost: cost / 100, profit: (paisa(input.total_collected_amount) - cost) / 100 }
}
