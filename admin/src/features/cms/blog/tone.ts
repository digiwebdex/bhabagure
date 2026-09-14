import type { BlogCategory } from '../../../lib/api/types'

/** Category chip colours — token names, the same three tones the website uses. */
export const toneClass: Record<BlogCategory['tone'], string> = {
  blue: 'bg-blue-tint text-blue-deep',
  purple: 'bg-purple-tint text-purple',
  orange: 'bg-orange-tint text-amber',
}
