import { useEffect, type RefObject } from 'react'

/** Closes a dropdown on a click outside `box`, on tabbing out of it, or on Escape. */
export function useDismiss(box: RefObject<HTMLElement | null>, open: boolean, close: () => void) {
  useEffect(() => {
    if (!open) return
    const outside = (event: Event) => box.current && !box.current.contains(event.target as Node) && close()
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && close()
    document.addEventListener('mousedown', outside)
    document.addEventListener('focusin', outside)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', outside)
      document.removeEventListener('focusin', outside)
      document.removeEventListener('keydown', onKey)
    }
  })
}
