/** A customer's initials where the old system had a photo: we keep no pictures of customers. */
export function CustomerAvatar({ name }: { name: string }) {
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0] ?? '')
    .join('')
    .toUpperCase()

  return <span aria-hidden="true" className="flex size-7 shrink-0 items-center justify-center rounded-pill bg-blue-tint text-11 font-bold text-blue-deep">{initials}</span>
}
