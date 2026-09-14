import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import '@fontsource/hind-siliguri/400.css'
import '@fontsource/hind-siliguri/500.css'
import '@fontsource/hind-siliguri/600.css'
import '@fontsource/hind-siliguri/700.css'
import '@fontsource-variable/bricolage-grotesque/opsz.css'
import './index.css'

import './i18n'
import App from './App.tsx'
import { storedTheme } from './lib/useTheme'

// Apply the saved theme before the first paint.
document.documentElement.dataset.theme = storedTheme()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
