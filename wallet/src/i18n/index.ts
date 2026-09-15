import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import bn from './bn.json'
import en from './en.json'

export const LOCALES = ['bn', 'en'] as const
export type AppLocale = (typeof LOCALES)[number]

// The same key as the admin and the website prototype.
const STORAGE_KEY = 'bh-lang'

function storedLocale(): AppLocale {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'en' ? 'en' : 'bn'
  } catch {
    return 'bn'
  }
}

void i18n.use(initReactI18next).init({
  resources: { bn: { translation: bn }, en: { translation: en } },
  lng: storedLocale(),
  fallbackLng: 'bn',
  supportedLngs: LOCALES,
  interpolation: { escapeValue: false },
})

function applyLocale(locale: string) {
  document.documentElement.lang = locale
  document.title = i18n.t('title')
}

applyLocale(i18n.language)
i18n.on('languageChanged', (locale) => {
  applyLocale(locale)
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // Private browsing: the choice lasts for this visit.
  }
})

export default i18n
