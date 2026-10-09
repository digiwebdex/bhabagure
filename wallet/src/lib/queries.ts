import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api, type Data } from "./api";

/** Shapes from api/app/Wallet/Http/Controllers (docs/phase-7-hr-attendance-bonus-wallet.md §8). */

export type Direction = "in" | "out";

export type Me = {
  name: string;
  email: string;
  locale: string;
  super_admin: boolean;
};

/** Who has a personal wallet (docs/personal-wallets.md): the super admin's Access screen. Never what is in one. */
export type Member = {
  staff_id: number;
  name: string;
  email: string;
  super_admin: boolean;
  has_wallet: boolean;
  granted_at: string | null;
};

export type Summary = {
  balance: number;
  total_in: number;
  total_out: number;
  count_in: number;
  count_out: number;
  month_in: number;
  last_entry_at: string | null;
  by_source: { name: string; in: number; out: number }[];
  due_total: number;
};

/** What an edit changed (docs/wallet-edits.md). Evidence: the file names, null for none. */
export type Change = {
  field: "direction" | "amount" | "source" | "reference" | "occurred_on" | "evidence";
  before: string | number | null;
  after: string | number | null;
};

/**
 * An entry as the list shows it (api/app/Wallet/Http/EntryRows.php). It stands, or was edited (a corrected entry took
 * its place) or deleted; a corrected entry says what the edit changed. Reversals are never rows.
 */
export type Entry = {
  id: number;
  direction: Direction;
  amount: number;
  kind: "entry" | "deal_advance" | "deal_payment";
  source: string;
  source_id: number | null;
  deal: { id: number; name: string | null; deleted: boolean } | null;
  reference: string;
  occurred_on: string;
  has_evidence: boolean;
  evidence_name: string | null;
  created_at: string | null;
  status: "standing" | "edited" | "deleted";
  changed_at: string | null;
  change_reason: string | null;
  replaced_by_id: number | null;
  replaces_id: number | null;
  changes: Change[];
};

/** View: the entry with every version of it, oldest first. */
export type EntryWithVersions = Entry & { versions: Entry[] };

export type Deal = {
  id: number;
  name: string;
  total: number;
  note: string | null;
  received: number;
  due: number;
  settled: boolean;
  created_at: string | null;
  /** Its standing advance and payments, by date. */
  payments: Entry[];
};

/** A deal's own history (View): made with its total and advance, then each edit with what changed and why. */
export type DealStep =
  | { action: "deal_created"; at: string | null; changes: { total: number; advance: number } | null }
  | {
      action: "deal_edited";
      at: string | null;
      changes: {
        changes: Partial<Record<"name" | "total" | "note", [string | number | null, string | number | null]>>;
        reason: string | null;
      } | null;
    };

export type DealWithHistory = Deal & { history: DealStep[] };

export type Source = { id: number; name: string };
export type Preset = { id: number; direction: Direction; label: string };

export function useMe() {
  return useQuery({
    queryKey: ["me"],
    queryFn: ({ signal }) =>
      api.get<Data<Me>>("auth/me", signal).then((r) => r.data),
    retry: false,
  });
}

export const useSummary = () =>
  useQuery({
    queryKey: ["summary"],
    queryFn: ({ signal }) =>
      api.get<Data<Summary>>("summary", signal).then((r) => r.data),
  });
export const useSources = () =>
  useQuery({
    queryKey: ["sources"],
    queryFn: ({ signal }) =>
      api.get<Data<Source[]>>("sources", signal).then((r) => r.data),
  });
export const usePresets = () =>
  useQuery({
    queryKey: ["presets"],
    queryFn: ({ signal }) =>
      api.get<Data<Preset[]>>("presets", signal).then((r) => r.data),
  });
export const useMembers = () =>
  useQuery({
    queryKey: ["members"],
    queryFn: ({ signal }) =>
      api.get<Data<Member[]>>("members", signal).then((r) => r.data),
  });
export const useDeals = () =>
  useQuery({
    queryKey: ["deals"],
    queryFn: ({ signal }) =>
      api.get<Data<Deal[]>>("deals", signal).then((r) => r.data),
  });

/** The history, as the book stands; with `withChanges` ("Show edits and deletions"), edited and deleted entries too. */
export function useHistory(direction: Direction | "all", page: number, withChanges = false) {
  return useQuery({
    queryKey: ["history", direction, page, withChanges],
    queryFn: ({ signal }) =>
      api.get<
        Data<Entry[]> & {
          meta: { current_page: number; last_page: number; total: number };
        }
      >(`transactions?direction=${direction}&page=${page}${withChanges ? "&history=1" : ""}`, signal),
    placeholderData: (previous) => previous,
  });
}

export const useEntry = (id: number) =>
  useQuery({
    queryKey: ["entry", id],
    queryFn: ({ signal }) =>
      api.get<Data<EntryWithVersions>>(`transactions/${id}`, signal).then((r) => r.data),
  });

export const useDeal = (id: number) =>
  useQuery({
    queryKey: ["deal", id],
    queryFn: ({ signal }) =>
      api.get<Data<DealWithHistory>>(`deals/${id}`, signal).then((r) => r.data),
  });

/**
 * Money moved or something renamed: the balance, breakdown, deals, history and open views all follow. The change counts
 * as done once what is on screen has reloaded, so a dialog closes onto current figures and the next click (an Add
 * payment's amount, a row's View) never acts on the old ones.
 */
export function useBookChange<TVariables, TResponse>(
  send: (variables: TVariables) => Promise<TResponse>,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: send,
    onSuccess: () =>
      Promise.all(
        ["summary", "deals", "deal", "entry", "history", "sources", "presets"].map((key) =>
          client.invalidateQueries({ queryKey: [key] }),
        ),
      ),
  });
}

export const actions = {
  password: (body: { email: string; password: string }) =>
    api.post<Data<{ signed_in: boolean }>>("auth/password", body),
  signOut: () => api.post<Data<{ signed_in: boolean }>>("auth/sign-out"),
  record: (form: FormData) => api.post<Data<Entry>>("transactions", form),
  // Edit and Delete (docs/wallet-edits.md): an edit answers with the corrected entry, which takes the old one's place.
  editEntry: ({ id, form }: { id: number; form: FormData }) =>
    api.post<Data<Entry>>(`transactions/${id}/edit`, form),
  deleteEntry: ({ id, reason }: { id: number; reason: string }) =>
    api.post<Data<{ deleted: boolean }>>(`transactions/${id}/delete`, { reason }),
  createDeal: (form: FormData) => api.post<Data<Deal>>("deals", form),
  editDeal: ({ id, ...body }: { id: number; name: string; total: number; note: string; reason: string }) =>
    api.post<Data<Deal>>(`deals/${id}/edit`, body),
  deleteDeal: ({ id, reason }: { id: number; reason: string }) =>
    api.post<Data<{ removed: number }>>(`deals/${id}/delete`, { reason }),
  pay: ({ id, form }: { id: number; form: FormData }) =>
    api.post<Data<Deal>>(`deals/${id}/payments`, form),
  addSource: (name: string) => api.post<Data<Source[]>>("sources", { name }),
  renameSource: ({ id, name }: { id: number; name: string }) =>
    api.post<Data<Source[]>>(`sources/${id}/rename`, { name }),
  archiveSource: (id: number) =>
    api.post<Data<Source[]>>(`sources/${id}/archive`),
  addPreset: (body: { direction: Direction; label: string }) =>
    api.post<Data<Preset[]>>("presets", body),
  editPreset: ({ id, label }: { id: number; label: string }) =>
    api.post<Data<Preset[]>>(`presets/${id}/edit`, { label }),
  removePreset: (id: number) =>
    api.post<Data<Preset[]>>(`presets/${id}/remove`),
  grant: (staffId: number) =>
    api.post<Data<Member[]>>("members", { staff_id: staffId }),
  revoke: (staffId: number) =>
    api.post<Data<Member[]>>(`members/${staffId}/revoke`),
};
