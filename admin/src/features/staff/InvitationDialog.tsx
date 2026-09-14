import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, useToast } from '../../components/ui/feedback'
import { useFormat } from '../../lib/useFormat'
import type { Invitation } from './api'

/**
 * The invitation link, shown once (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): it sets the new person's password,
 * so it is sent to them — by the email the API already tried, or by WhatsApp from here. Closing the dialog loses it; a
 * new link can be made from the person's record, which cancels this one.
 */
export function InvitationDialog({ name, email, phone, invitation, onClose }: { name: string; email: string; phone: string | null; invitation: Invitation; onClose: () => void }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const toast = useToast()
  // To their own number when it's on file; otherwise WhatsApp asks who to send it to.
  const digits = (phone ?? '').replace(/\D/g, '').replace(/^0/, '880')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(invitation.url)
      toast(t('staff.copied'))
    } catch {
      toast(t('staff.copyFailed'), 'error')
    }
  }

  return (
    <Dialog open onClose={onClose} title={t('staff.invitationTitle', { name })}>
      <p className="m-0 text-13.5 leading-1.6">{t('staff.invitationNote', { name, date: dateTime(invitation.expires_at) })}</p>
      <p className={`m-0 rounded-10 px-3 py-2 text-13 ${invitation.email === 'sent' ? 'bg-green-tint text-green-deep' : 'bg-orange-tint text-amber'}`}>
        {t(`staff.inviteEmail.${invitation.email}`, { email })}
      </p>
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('staff.inviteLink')}</span>
        <input readOnly value={invitation.url} onFocus={(event) => event.target.select()} className="w-full rounded-10 border border-app-line bg-app-surface-2 px-3 py-2 font-display text-12" data-testid="invitation-link" />
      </label>
      <div className="flex flex-wrap justify-end gap-2">
        <a href={`https://wa.me/${digits}?text=${encodeURIComponent(t('staff.inviteMessage', { name, url: invitation.url }))}`} target="_blank" rel="noopener noreferrer" className={buttonClass('outline')}>
          {t('staff.sendWhatsApp')}
        </a>
        <button type="button" className={buttonClass('outline')} onClick={() => void copy()}>
          {t('staff.copyLink')}
        </button>
        <button type="button" className={buttonClass('primary')} onClick={onClose}>
          {t('staff.done')}
        </button>
      </div>
    </Dialog>
  )
}
