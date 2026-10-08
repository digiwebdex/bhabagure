import 'server-only';

import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';
import { loadLegalPages } from '@/lib/content/source';
import { formattersFor } from '@/lib/formatters';

import { LEGAL_STATUS, legalDocument, type LegalSlug } from './documents';

/** The page as staff published it in the admin (docs/legal-pages.md), in this language; null while only the draft exists. */
async function published(slug: LegalSlug, locale: AppLocale) {
  const page = (await loadLegalPages())[slug];
  return page ? { title: page.title[locale], body: page.body[locale], publishedAt: page.publishedAt } : null;
}

/** Title and indexing: a published page is indexed; the built-in draft, pending the client's own text, is not. */
export async function legalMetadata(slug: LegalSlug, locale: AppLocale): Promise<Metadata> {
  const page = await published(slug, locale);
  if (page) return { title: page.title };
  return { title: legalDocument(slug, locale).title, robots: LEGAL_STATUS === 'draft' ? { index: false, follow: true } : undefined };
}

/**
 * A legal page: the text published in Admin → Website → Legal pages, or until then the built-in draft with its banner.
 * Server component: no client JavaScript.
 */
export async function LegalPage({ slug, locale }: { slug: LegalSlug; locale: AppLocale }) {
  const t = await getTranslations({ locale, namespace: 'legal' });
  const page = await published(slug, locale);

  if (page) {
    return (
      <article className="mx-auto flex max-w-narrow flex-col gap-5 px-section-x py-section-y">
        <header className="flex flex-col gap-1">
          <h1 className="text-fluid-21-28 font-bold tracking-title">{page.title}</h1>
          <p className="text-13 text-muted">{t('updated', { date: formattersFor(locale).date(page.publishedAt.slice(0, 10)) })}</p>
        </header>
        {/* Sanitised by the API when it was published, as blog posts are. */}
        <div className="prose-post text-15 leading-1.7 text-ink-soft" data-testid="legal-body" dangerouslySetInnerHTML={{ __html: page.body }} />
      </article>
    );
  }

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
