import type { PostView } from '@/lib/content/views';

/** Category pill colours by tone (a token name stored with the category, never a hex). */
export const categoryTone: Record<PostView['category']['tone'], string> = {
  blue: 'bg-blue-tint text-blue-deep',
  purple: 'bg-purple-tint text-purple',
  orange: 'bg-orange-tint text-amber',
};
