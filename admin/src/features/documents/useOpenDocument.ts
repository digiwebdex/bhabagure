import { useTranslation } from 'react-i18next'

import { useToast } from '../../components/ui/feedback'
import { fetchDocument } from '../../lib/api/client'
import { documentFilePath } from './api'

/** Opens a traveller's upload from memory (the token can't ride on a plain link). Decrypted by the API, never cached. */
export function useOpenDocument() {
  const { t } = useTranslation()
  const toast = useToast()
  return async (id: number) => {
    // Opened before the request so the browser treats it as the click, not a pop-up.
    const tab = window.open('', '_blank')
    try {
      const url = URL.createObjectURL(await fetchDocument(documentFilePath(id)))
      if (tab) tab.location.href = url
      else window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      tab?.close()
      toast(t('documents.openFailed'), 'error')
    }
  }
}
