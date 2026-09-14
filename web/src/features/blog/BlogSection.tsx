import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';

import { NewsletterCard } from '../newsletter/NewsletterCard';
import { BlogExplorer } from './BlogExplorer';

/** News and travel guides with category chips; the newsletter card closes the section. */
export async function BlogSection({ locale, posts, categories }: { locale: AppLocale; posts: SiteViews['posts']; categories: SiteViews['categories'] }) {
  const t = await getTranslations({ locale, namespace: 'sections.blog' });

  return (
    <section id="blog" className="border-y border-hairline-soft bg-paper-alt">
      <div className="mx-auto flex max-w-site flex-col gap-7 px-section-x py-section-y">
        <BlogExplorer posts={posts} categories={categories} heading={t('heading')} lede={t('lede')} />
        <NewsletterCard />
      </div>
    </section>
  );
}
