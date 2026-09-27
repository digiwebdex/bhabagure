import type { Channel } from './api'

/** Initials on the channel's colour: WhatsApp green, Messenger blue (the design's inbox). */
export function Avatar({ name, channel, size = 'md' }: { name: string; channel: Channel; size?: 'md' | 'lg' }) {
  const initials =
    name
      .replace(/[^\p{L}\p{N}\s]/gu, '')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((word) => word.charAt(0).toUpperCase())
      .join('') || '?'
  return (
    <span
      aria-hidden
      className={`flex shrink-0 items-center justify-center rounded-full font-display font-bold text-white ${size === 'lg' ? 'size-11 text-15' : 'size-9.5 text-13'} ${channel === 'whatsapp' ? 'bg-green' : 'bg-blue'}`}
    >
      {initials}
    </span>
  )
}

export function ChannelBadge({ channel }: { channel: Channel }) {
  return (
    <span className={`rounded-pill px-2 py-0.25 text-11 font-bold text-white ${channel === 'whatsapp' ? 'bg-green' : 'bg-blue'}`}>{channel === 'whatsapp' ? 'WhatsApp' : 'Messenger'}</span>
  )
}
