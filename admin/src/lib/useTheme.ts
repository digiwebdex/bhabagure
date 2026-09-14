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
    try {
      localStorage.setItem(STORAGE_KEY, theme)
    } catch {
      // Storage unavailable: the theme still applies for this session.
    }
  }, [theme])

  return {
    theme,
    toggle: () => setTheme((previous) => (previous === 'light' ? 'dark' : 'light')),
  }
}
