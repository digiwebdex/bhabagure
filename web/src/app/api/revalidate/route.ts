import { revalidateTag } from 'next/cache';
import { NextResponse, type NextRequest } from 'next/server';

const TAGS = new Set(['packages', 'departures', 'posts', 'team', 'reviews', 'gallery', 'visas', 'settings', 'creator']);

/**
 * Called by the Laravel API after a CMS save: POST { "tags": ["packages"] } with
 * `Authorization: Bearer <REVALIDATE_SECRET>`. The next visit then renders fresh content.
 */
export async function POST(request: NextRequest) {
  const secret = process.env.REVALIDATE_SECRET;
  if (!secret || request.headers.get('authorization') !== `Bearer ${secret}`) {
    return NextResponse.json({ message: 'Unauthorized' }, { status: 401 });
  }
  const body = (await request.json().catch(() => null)) as { tags?: unknown } | null;
  const tags = Array.isArray(body?.tags) ? body.tags.filter((tag): tag is string => typeof tag === 'string' && TAGS.has(tag)) : [];
  if (tags.length === 0) return NextResponse.json({ message: 'No known tags' }, { status: 422 });

  // expire: 0 — an editor who just saved should see the change on the next page load, not a stale copy.
  for (const tag of tags) revalidateTag(tag, { expire: 0 });
  return NextResponse.json({ revalidated: tags });
}
