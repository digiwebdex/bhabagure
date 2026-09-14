'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { SiteViews } from '@/lib/content/views';

import { PostCard } from './PostCard';

interface BlogExplorerProps {
  posts: SiteViews['posts'];
  categories: SiteViews['categories'];
  heading: string;
  lede: string;
}

export function BlogExplorer({ posts, categories, heading, lede }: BlogExplorerProps) {
  const t = useTranslations('blog');
  const [category, setCategory] = useState('all');
  const visible = category === 'all' ? posts : posts.filter((p) => p.category.slug === category);

  const chip = (value: string, label: string) => {
    const active = category === value;
    return (
      <button
        key={value}
        type="button"
        aria-pressed={active}
        onClick={() => setCategory(value)}
        className={`cursor-pointer rounded-pill border-chip px-3.75 py-2 text-13 font-semibold whitespace-nowrap ${
          active ? 'border-blue bg-blue text-white' : 'border-input bg-white text-muted hover:border-blue'
        }`}
      >
        {label}
      </button>
    );
  };

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-3.5">
        <SectionHeading heading={heading} lede={lede} className="min-w-0" />
        <div className="flex flex-wrap gap-1.75">
          {chip('all', t('all'))}
          {categories.map((c) => chip(c.slug, c.name))}
        </div>
      </div>
      <div className="grid-auto-fit-300 grid gap-4.5">
        {visible.map((post) => (
          <PostCard key={post.slug} post={post} />
        ))}
      </div>
    </>
  );
}
