import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { categoryTone } from '@/features/blog/tone';
import { SiteChrome } from '@/features/SiteChrome';
import { Link } from '@/i18n/navigation';
import { routing, type AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { loadContent } from '@/lib/content/source';
import { formattersFor } from '@/lib/formatters';
import { localizedPath, postPath } from '@/lib/links';
import { blogPosting, jsonLd } from '@/lib/structured-data';

export async function generateStaticParams() {
  const { posts } = await loadContent();
  return routing.locales.flatMap((locale) => posts.filter((p) => p.status === 'published').map((p) => ({ locale, slug: p.slug })));
}

export async function generateMetadata({ params }: PageProps<'/[locale]/site/blog/[slug]'>): Promise<Metadata> {
  const { locale, slug } = await params;
  const views = await getSiteViews(locale as AppLocale);
  const post = views.posts.find((p) => p.slug === slug);
  if (!post) return {};
  const t = await getTranslations({ locale, namespace: 'common' });
  const path = postPath(slug);
  return {
    title: `${post.title} · ${t('brand')}`,
    description: post.excerpt,
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: localizedPath(locale as AppLocale, path), languages: { bn: path, en: localizedPath('en', path) } },
    openGraph: { title: post.title, description: post.excerpt, type: 'article', publishedTime: post.publishedAt },
  };
}

export default async function BlogPostPage({ params }: PageProps<'/[locale]/site/blog/[slug]'>) {
  const { locale: param, slug } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);
  const post = views.posts.find((p) => p.slug === slug);
  if (!post) notFound();
  const t = await getTranslations({ locale, namespace: 'blog' });
  const f = formattersFor(locale);
  const path = postPath(slug);

  return (
    <SiteChrome locale={locale} views={views} pathname={path}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(blogPosting(post, siteUrl() + localizedPath(locale, path), views.settings)) }} />
      <article className="mx-auto flex max-w-narrow flex-col gap-4 px-section-x py-section-y">
        <Link href="/#blog" className="text-14 font-semibold">
          {t('backToNews')}
        </Link>
        <div className="flex flex-wrap items-center gap-2.5">
          <span className={`rounded-pill px-2.5 py-1 font-display text-11 font-extrabold tracking-step uppercase ${categoryTone[post.category.tone]}`}>{post.category.name}</span>
          <time dateTime={post.publishedAt} className="text-13 text-muted-label">
            {f.date(post.publishedAt)}
          </time>
        </div>
        <h1 className="text-fluid-30-42 leading-1.18 font-bold tracking-display text-pretty">{post.title}</h1>
        <p className="text-13 text-muted-label">
          {post.author} · {t('readingTime', { minutesText: f.number(post.readingMinutes) })}
        </p>
        {/* Body HTML is sanitised by the API against an allow-list when the CMS saves it. */}
        <div className="prose-post text-16 leading-1.75 text-ink" dangerouslySetInnerHTML={{ __html: post.body }} />
      </article>
    </SiteChrome>
  );
}
