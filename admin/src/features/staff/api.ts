import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'

import { api, upload } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** Shapes from api/app/Http/Controllers/Api/V1/Admin/{Staff,StaffDocument,Role}Controller.php (docs/phase-7-hr-attendance-bonus-wallet.md §4). */

export type StaffStatus = 'invited' | 'active' | 'suspended'
export type StaffRole = { name: string; name_en: string; name_bn: string; is_system: boolean }

export type StaffRow = {
  id: number
  employee_code: string
  name: string
  email: string
  phone: string | null
  status: StaffStatus
  role: StaffRole | null
  is_super_admin: boolean
  designation: string | null
  joined_on: string | null
  last_login_at: string | null
  invitation_expires_at: string | null
  closed_sales_month: number
  /** Expired or expiring documents; null when the viewer can't see staff documents. */
  documents_attention: number | null
}

export type PayoutMethod = 'bank' | 'bkash' | 'nagad' | 'rocket' | 'cash'

export type StaffProfile = {
  designation: string | null
  joined_on: string | null
  left_on: string | null
  date_of_birth: string | null
  nid_number: string | null
  address: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  payout_method: PayoutMethod | null
  payout_account: string | null
}

export type StaffDetail = StaffRow & {
  locale: 'bn' | 'en'
  profile: StaffProfile
  documents: StaffDocument[] | null
  actions: Record<'edit' | 'change_email' | 'change_role' | 'suspend' | 'reactivate' | 'reinvite' | 'password_reset' | 'upload_documents', boolean>
}

export const DOCUMENT_TYPES = ['passport', 'nid', 'driving_licence', 'cv', 'appointment_letter', 'contract', 'certificate', 'photo', 'other'] as const
export type DocumentType = (typeof DOCUMENT_TYPES)[number]
export type DocumentStatus = 'expired' | 'expiring' | 'renew_soon' | 'valid' | 'no_expiry'

export type StaffDocument = {
  id: number
  staff: { id: number; name: string; employee_code: string; status: StaffStatus }
  type: DocumentType
  title: string | null
  number: string | null
  issued_on: string | null
  expires_on: string | null
  status: DocumentStatus
  /** Negative once expired. */
  days_left: number | null
  mime: string
  bytes: number
  note: string | null
  uploaded_by: string | null
  uploaded_at: string | null
  archived_at: string | null
  archived_by: string | null
  archive_reason: string | null
  replaced_by_id: number | null
}

export type StaffOptions = { roles: StaffRole[]; payout_methods: PayoutMethod[]; document_types: DocumentType[] }

/** An invitation link, shown once so it can be sent by WhatsApp; `email` says whether the email reached anyone. */
export type Invitation = { url: string; email: 'sent' | 'off' | 'failed'; expires_at: string }

export type StaffListStatus = 'current' | 'active' | 'invited' | 'suspended' | 'all'
export type StaffFilters = { status: StaffListStatus; search: string; page: number }
export type StaffList = Paginated<StaffRow> & { meta: { status_counts: Record<StaffStatus, number> } }

export const roleLabel = (role: StaffRole | null, locale: 'bn' | 'en') => (role ? (locale === 'en' ? role.name_en : role.name_bn || role.name_en) : '—')

export function useStaffList({ status, search, page }: StaffFilters) {
  const params = new URLSearchParams({ status, page: String(page) })
  if (search.trim()) params.set('search', search.trim())
  return useQuery({
    queryKey: ['staff', status, search.trim(), page],
    queryFn: ({ signal }) => api.get<StaffList>(`admin/staff?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useStaffOptions() {
  return useQuery({ queryKey: ['staff-options'], queryFn: ({ signal }) => api.get<Data<StaffOptions>>('admin/staff/options', signal), staleTime: 60_000 })
}

export function useStaffMember(id: number) {
  return useQuery({ queryKey: ['staff-member', id], queryFn: ({ signal }) => api.get<Data<StaffDetail>>(`admin/staff/${id}`, signal) })
}

export type NewStaff = { name: string; email: string; phone: string; role: string; designation: string; joined_on: string; locale: 'bn' | 'en' }

export function useCreateStaff() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (body: NewStaff) => api.post<Data<StaffDetail> & { invitation: Invitation }>('admin/staff', body),
    onSuccess: (response) => {
      client.setQueryData(['staff-member', response.data.id], { data: response.data })
      void client.invalidateQueries({ queryKey: ['staff'] })
    },
  })
}

/** Every action on a person answers with their updated record; the cache takes it directly. */
export function useStaffAction<TVariables, TResponse extends Data<StaffDetail> = Data<StaffDetail>>(id: number, send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData(['staff-member', id], { data: response.data })
      void client.invalidateQueries({ queryKey: ['staff'] })
      void client.invalidateQueries({ queryKey: ['assignable-staff'] })
    },
  })
}

export const staffActions = {
  update: (id: number) => (body: Partial<Pick<StaffDetail, 'name' | 'email' | 'phone' | 'locale'>> & Partial<StaffProfile>) => api.put<Data<StaffDetail>>(`admin/staff/${id}`, body),
  role: (id: number) => (role: string) => api.put<Data<StaffDetail>>(`admin/staff/${id}/role`, { role }),
  suspend: (id: number) => (body: { reason: string; left_on: string | null }) => api.post<Data<StaffDetail>>(`admin/staff/${id}/suspend`, body),
  reactivate: (id: number) => () => api.post<Data<StaffDetail>>(`admin/staff/${id}/reactivate`),
  reinvite: (id: number) => () => api.post<Data<StaffDetail> & { invitation: Invitation }>(`admin/staff/${id}/invitation`),
}

export function usePasswordReset(id: number) {
  return useMutation({ mutationFn: () => api.post<Data<{ email: Invitation['email'] }>>(`admin/staff/${id}/password-reset`) })
}

// ── Staff documents (the Vault) ─────────────────────────────────────────────────────────────────────

export type VaultStatus = 'attention' | DocumentStatus | 'all'
export type VaultFilters = { status: VaultStatus; archived: boolean; page: number }
export type VaultList = Paginated<StaffDocument> & { meta: { status_counts: Record<'attention' | DocumentStatus, number> } }

export function useStaffDocuments({ status, archived, page }: VaultFilters) {
  const params = new URLSearchParams({ status, page: String(page) })
  if (archived) params.set('archived', '1')
  return useQuery({
    queryKey: ['staff-documents', status, archived, page],
    queryFn: ({ signal }) => api.get<VaultList>(`admin/staff-documents?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useDocumentOwners(enabled: boolean) {
  return useQuery({
    queryKey: ['staff-document-owners'],
    queryFn: ({ signal }) => api.get<Data<{ id: number; name: string; employee_code: string; status: StaffStatus }[]>>('admin/staff-documents/owners', signal),
    enabled,
  })
}

export type DocumentFields = { type: DocumentType; title: string; number: string; issued_on: string; expires_on: string; note: string; file: File }

/** Upload, replace and archive: the Vault list and the person's record change (the sidebar badge refreshes after any mutation). */
export function useDocumentAction<TVariables>(send: (variables: TVariables) => Promise<Data<StaffDocument>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      void client.invalidateQueries({ queryKey: ['staff-documents'] })
      void client.invalidateQueries({ queryKey: ['staff-member', response.data.staff.id] })
      void client.invalidateQueries({ queryKey: ['staff'] })
    },
  })
}

const documentForm = ({ file, ...fields }: DocumentFields) => {
  const body = new FormData()
  for (const [key, value] of Object.entries(fields)) if (value.trim() !== '') body.append(key, value.trim())
  body.append('file', file)
  return body
}

export const documentActions = {
  upload: (staffId: number) => (fields: DocumentFields) => upload<Data<StaffDocument>>(`admin/staff/${staffId}/documents`, documentForm(fields), () => undefined),
  replace: (documentId: number) => (fields: DocumentFields) => upload<Data<StaffDocument>>(`admin/staff-documents/${documentId}/replace`, documentForm(fields), () => undefined),
  archive: (documentId: number) => (reason: string) => api.post<Data<StaffDocument>>(`admin/staff-documents/${documentId}/archive`, { reason }),
}

export const staffDocumentFilePath = (id: number) => `admin/staff-documents/${id}/file`

/** A document's name as listed: its type, and the title when it has one. */
export const documentName = (t: TFunction, document: Pick<StaffDocument, 'type' | 'title'>) =>
  document.title ? `${t(`vault.types.${document.type}`)} · ${document.title}` : t(`vault.types.${document.type}`)

// ── Roles ───────────────────────────────────────────────────────────────────────────────────────

export type RoleRow = StaffRole & { id: number; all_permissions: boolean; users: number; permissions: string[] }
export type PermissionRow = { name: string; module: string; name_en: string; name_bn: string; reserved: boolean }
export type RolesData = { roles: RoleRow[]; permissions: PermissionRow[] }

export function useRoles() {
  return useQuery({ queryKey: ['roles'], queryFn: ({ signal }) => api.get<Data<RolesData>>('admin/roles', signal) })
}

/** Every roles call answers with the full role list. */
export function useRoleAction<TVariables>(send: (variables: TVariables) => Promise<Data<{ roles: RoleRow[] }>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData<Data<RolesData>>(['roles'], (previous) => (previous ? { data: { ...previous.data, roles: response.data.roles } } : previous))
      void client.invalidateQueries({ queryKey: ['staff-options'] })
    },
  })
}

export const roleActions = {
  create: (body: { name_en: string; name_bn: string; permissions: string[] }) => api.post<Data<{ roles: RoleRow[] }>>('admin/roles', body),
  rename: ({ id, ...body }: { id: number; name_en: string; name_bn: string }) => api.put<Data<{ roles: RoleRow[] }>>(`admin/roles/${id}`, body),
  set: ({ id, permission, granted }: { id: number; permission: string; granted: boolean }) => api.put<Data<{ roles: RoleRow[] }>>(`admin/roles/${id}/permissions`, { permission, granted }),
  remove: (id: number) => api.delete<Data<{ roles: RoleRow[] }>>(`admin/roles/${id}`),
}

/** The signed-in person's own HR record (GET admin/profile/record). */
export type MyRecord = Omit<StaffProfile, 'left_on'> & { employee_code: string }

export function useMyRecord() {
  return useQuery({ queryKey: ['my-record'], queryFn: ({ signal }) => api.get<Data<MyRecord>>('admin/profile/record', signal) })
}
