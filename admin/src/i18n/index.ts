import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import en from './en.json'

/**
 * The staff panel is English only (the client's decision, 2026-09-16): staff screens, their API messages and the wallet
 * app. What customers read — the website, the portal, invoices and the messages sent to them — stays Bangla first, and
 * staff still type that content in both languages.
 */
export const LOCALES = ['en'] as const
export type AppLocale = (typeof LOCALES)[number]

void i18n.use(initReactI18next).init({
  resources: { en: { translation: en } },
  lng: 'en',
  fallbackLng: 'en',
  supportedLngs: LOCALES,
  interpolation: { escapeValue: false },
})

document.documentElement.lang = 'en'
document.title = i18n.t('meta.title')

export default i18n
