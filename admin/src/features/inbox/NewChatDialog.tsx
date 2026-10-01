import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { useStartChat } from './api'
import { ChannelBadge } from './parts'
import { whatsAppNumber } from './phone'

/**
 * "New chat" (client, 2026-10-01; docs/admin-inbox.md §8): a WhatsApp chat from the main number with a number staff type
 * in, with an optional name and first message. A number that has a chat opens that chat. Messenger can't be started from
 * here: Facebook lets a Page answer only people who wrote to it first.
 */
export function NewChatDialog({ initialPhone, onClose, onOpened }: { initialPhone: string; onClose: () => void; onOpened: (id: number) => void }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const start = useStartChat()
  const [phone, setPhone] = useState(initialPhone)
  const [name, setName] = useState('')
  const [body, setBody] = useState('')
  const number = whatsAppNumber(phone)
  const error = start.error instanceof ApiError && start.error.status === 422 ? start.error : null

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (start.isPending || phone.trim() === '') return
    start.mutate(
      { phone, name, body },
      {
        onSuccess: (response) => {
          toast(response.data.existing ? t('inbox.chatOpened') : t('inbox.chatStarted'))
          onOpened(response.data.id)
        },
      },
    )
  }

  return (
    <Dialog open onClose={onClose} title={t('inbox.newChatTitle')}>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4" data-testid="new-chat">
        <p className="m-0 flex flex-wrap items-center gap-2 text-13 leading-1.5 text-app-muted">
          <ChannelBadge channel="whatsapp" />
          {t('inbox.newChatNote')}
        </p>
        <TextInput
          label={t('inbox.newChatPhone')}
          value={phone}
          onChange={setPhone}
          error={error?.field('phone')}
          hint={number ? t('inbox.newChatTo', { number: digits(`+${number}`) }) : t('inbox.newChatPhoneHint')}
          inputMode="tel"
          autoComplete="off"
          maxLength={40}
          autoFocus
        />
        <TextInput label={t('inbox.newChatName')} value={name} onChange={setName} error={error?.field('name')} hint={t('inbox.newChatNameHint')} maxLength={120} />
        <TextArea label={t('inbox.newChatMessage')} value={body} onChange={setBody} error={error?.field('body')} hint={t('inbox.newChatMessageHint')} rows={4} maxLength={4000} />
        {start.error && !error ? <ErrorNotice error={start.error} /> : null}
        <p className="m-0 text-12 text-app-muted">{t('inbox.newChatLimit')}</p>
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('cta')} aria-disabled={start.isPending || phone.trim() === ''}>
            {start.isPending ? t('inbox.startingChat') : body.trim() !== '' ? t('inbox.startAndSend') : t('inbox.startChat')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
