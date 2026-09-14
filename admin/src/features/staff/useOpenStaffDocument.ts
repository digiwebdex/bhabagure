import { useTranslation } from 'react-i18next'

import { useToast } from '../../components/ui/feedback'
import { fetchDocument } from '../../lib/api/client'
import { staffDocumentFilePath } from './api'

/** Opens a staff document from memory: the API decrypts it, never caches it, and records the opening in the audit log. */
export function useOpenStaffDocument() {
  const { t } = useTranslation()
  const toast = useToast()
  return async (id: number) => {
    // Opened before the request so the browser treats it as the click, not a pop-up.
    const tab = window.open('', '_blank')
    try {
      const url = URL.createObjectURL(await fetchDocument(staffDocumentFilePath(id)))
      if (tab) tab.location.href = url
      else window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      tab?.close()
      toast(t('vault.openFailed'), 'error')
    }
  }
}
