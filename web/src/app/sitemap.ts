import type { MetadataRoute } from 'next';

import { siteUrl } from '@/lib/content';
import { loadContent } from '@/lib/content/source';
import { localizedPath, packagePath, postPath, teamPath, visaPath } from '@/lib/links';

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const origin = siteUrl();
  const { packages, posts, visas } = await loadContent();
  const entry = (path: string, lastModified?: string): MetadataRoute.Sitemap[number] => ({
    url: origin + path,
    lastModified,
    alternates: { languages: { bn: origin + path, en: origin + localizedPath('en', path) } },
  });

  return [
    entry('/'),
    entry(teamPath),
    ...packages.filter((p) => p.status === 'published').map((p) => entry(packagePath(p.slug))),
    ...posts.filter((p) => p.status === 'published').map((p) => entry(postPath(p.slug), p.publishedAt)),
    ...visas.map((v) => entry(visaPath(v.slug), v.updatedAt ?? undefined)),
  ];
}
