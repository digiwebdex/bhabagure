import type { MetadataRoute } from 'next';

import { siteUrl } from '@/lib/content';
import { featureOn } from '@/lib/content/features';
import { loadContent } from '@/lib/content/source';
import { localizedPath, packagePath, postPath, teamPath, visaPath } from '@/lib/links';

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const origin = siteUrl();
  // A feature switched off in Site settings has its content left out already (docs/website-features.md); the team page
  // is checked here.
  const { packages, posts, visas, settings } = await loadContent();
  const entry = (path: string, lastModified?: string): MetadataRoute.Sitemap[number] => ({
    url: origin + path,
    lastModified,
    alternates: { languages: { bn: origin + path, en: origin + localizedPath('en', path) } },
  });

  return [
    entry('/'),
    ...(featureOn(settings, 'team') ? [entry(teamPath)] : []),
    ...packages.filter((p) => p.status === 'published').map((p) => entry(packagePath(p.slug))),
    ...posts.filter((p) => p.status === 'published').map((p) => entry(postPath(p.slug), p.publishedAt)),
    ...visas.map((v) => entry(visaPath(v.slug), v.updatedAt ?? undefined)),
  ];
}
