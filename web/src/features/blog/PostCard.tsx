'use client';

import { useTranslations } from 'next-intl';

import { Link } from '@/i18n/navigation';
import type { PostView } from '@/lib/content/views';
import { postPath } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';

import { categoryTone } from './tone';

/** Tag, date, title, excerpt, author and reading time. The title links to the full post and covers the card. */
export function PostCard({ post }: { post: PostView }) {
  const t = useTranslations('blog');
  const f = useFormatters();

  return (
    <article data-reveal className="relative flex min-w-0 flex-col gap-2.75 rounded-20 border border-hairline bg-white p-5.5 transition-shadow duration-250 hover:shadow-card-soft">
      <div className="flex flex-wrap items-center gap-2.5">
        <span className={`rounded-pill px-2.5 py-1 font-display text-11 font-extrabold tracking-step uppercase ${categoryTone[post.category.tone]}`}>
          {post.category.name}
        </span>
        <time dateTime={post.publishedAt} className="text-12.5 text-muted-label">
          {f.date(post.publishedAt)}
        </time>
      </div>
      <h3 className="text-18 leading-1.35 font-bold tracking-heading-sm text-pretty">
        <Link href={postPath(post.slug)} className="text-ink after:absolute after:inset-0 hover:text-ink">
          {post.title}
        </Link>
      </h3>
      <p className="line-clamp-6 text-14.5 leading-1.7 text-muted text-pretty">{post.excerpt}</p>
      <div className="mt-auto flex flex-wrap items-center justify-between gap-2.5 border-t border-hairline-faint pt-2.5">
        <span className="text-12.5 text-muted-label">{post.author}</span>
        <span className="font-display text-12 font-bold tracking-caps text-blue">{t('readingTime', { minutesText: f.number(post.readingMinutes) })}</span>
      </div>
    </article>
  );
}
