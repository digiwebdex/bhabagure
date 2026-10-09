import { api } from './api'

/** The API's limit (EvidenceVault::MAX_KB), checked before upload so the form can say so. */
export const MAX_EVIDENCE_BYTES = 5 * 1024 * 1024

/**
 * Opens an entry's evidence in a new tab: fetched with the wallet's own session, shown from a short-lived blob URL.
 * Any version's evidence opens, an edited one's too. Resolves false when it couldn't be opened.
 */
export async function openEvidence(entryId: number): Promise<boolean> {
  const tab = window.open('', '_blank')
  try {
    const url = URL.createObjectURL(await api.blob(`transactions/${entryId}/evidence`))
    if (tab) tab.location.href = url
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
    return true
  } catch {
    tab?.close()
    return false
  }
}
