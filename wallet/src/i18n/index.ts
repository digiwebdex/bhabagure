import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import en from './en.json'

/** The wallet is staff software: English only, like the admin panel (the client's decision, 2026-09-16). */
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
document.title = i18n.t('title')

export default i18n
