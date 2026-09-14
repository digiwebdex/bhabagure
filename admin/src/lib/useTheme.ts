import { useEffect, useState } from 'react'

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'bh-theme'

export function storedTheme(): Theme {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'dark' ? 'dark' : 'light'
  } catch {
    return 'light'
  }
}

/** Light/dark for the admin shell. Colours switch through [data-theme] on <html> (tokens/theme.css). */
export function useTheme() {
  const [theme, setTheme] = useState<Theme>(storedTheme)

  useEffect(() => {
    document.documentElement.dataset.theme = theme
  }, [theme])

  return {
    theme,
    // Stored only when someone toggles: writing on mount could overwrite a choice made meanwhile (another tab, or
    // storage set while the shell was still loading) with the value read before it.
    toggle: () => {
      const next: Theme = theme === 'light' ? 'dark' : 'light'
      try {
        localStorage.setItem(STORAGE_KEY, next)
      } catch {
        // Storage unavailable: the theme still applies for this session.
      }
      setTheme(next)
    },
  }
}
