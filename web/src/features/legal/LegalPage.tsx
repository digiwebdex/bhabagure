import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';

import { LEGAL_STATUS, legalDocument, type LegalSlug } from './documents';

/** A legal document with the draft banner. Server component: plain text, no client JavaScript. */
export async function LegalPage({ slug, locale }: { slug: LegalSlug; locale: AppLocale }) {
  const t = await getTranslations({ locale, namespace: 'legal' });
  const doc = legalDocument(slug, locale);

  return (
    <article className="mx-auto flex max-w-narrow flex-col gap-5 px-section-x py-section-y">
      {LEGAL_STATUS === 'draft' ? (
        <div role="note" className="rounded-14 border-2 border-orange-bright bg-orange-tint px-4 py-3 text-14 leading-1.6 text-amber">
          <strong className="block text-15">{t('draftTitle')}</strong>
          {t('draftNote')}
        </div>
      ) : null}
      <header className="flex flex-col gap-1">
        <h1 className="text-fluid-21-28 font-bold tracking-title">{doc.title}</h1>
        <p className="text-13 text-muted">{doc.updated}</p>
      </header>
      <p className="text-15 leading-1.7">{doc.intro}</p>
      {doc.sections.map((section) => (
        <section key={section.heading} className="flex flex-col gap-2">
          <h2 className="text-18 font-semibold">{section.heading}</h2>
          {section.paragraphs.map((paragraph) => (
            <p key={paragraph} className="text-15 leading-1.7 text-ink-soft">
              {paragraph}
            </p>
          ))}
        </section>
      ))}
    </article>
  );
}
