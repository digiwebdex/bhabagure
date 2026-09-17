import Image from 'next/image';
import { getTranslations } from 'next-intl/server';
import type { ComponentType } from 'react';

import { FacebookGlyph, YouTubeGlyph } from '@/components/brand/SocialGlyphs';
import { CountUp } from '@/components/motion/CountUp';
import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { CreatorView, ImageView } from '@/lib/content/views';

type Platform = 'facebook' | 'youtube';

/** Whole class names per platform, so Tailwind sees every one of them. */
const PLATFORM: Record<Platform, { Glyph: ComponentType<{ className?: string }>; fill: string; cover: string; ring: string; border: string }> = {
  facebook: {
    Glyph: FacebookGlyph,
    fill: 'bg-facebook',
    cover: 'from-facebook to-blue-abyss',
    ring: 'from-facebook via-orange-bright to-facebook',
    border: 'hover:border-facebook/70',
  },
  youtube: {
    Glyph: YouTubeGlyph,
    fill: 'bg-youtube',
    cover: 'from-youtube to-rust',
    ring: 'from-youtube via-orange-bright to-youtube',
    border: 'hover:border-youtube/70',
  },
};

/** "facebook.com/shishirdeb.traveller", "youtube.com/@shishirdeb": the address as people say it. */
const bare = (url: string) => url.replace(/^https:\/\/(www\.|m\.)?/, '').replace(/\/$/, '');

/**
 * Travel with Shishir Deb (docs/travel-host.md): the traveller behind the agency, a card each for his Facebook page and
 * YouTube channel, and the videos staff picked. On navy right after Services, so it is the first thing that stands out.
 * Cards rise in as they scroll into view, lift on hover, and the photo ring turns slowly — all still under reduced motion.
 * Renders nothing until Admin → Travel host has a profile with a link.
 */
export async function CreatorSection({ locale, creator }: { locale: AppLocale; creator: CreatorView | null }) {
  if (!creator) return null;
  const t = await getTranslations({ locale });
  const { facebook, youtube } = creator;

  return (
    <section id="travel-host" className="relative isolate overflow-hidden bg-ink-deep text-white" data-testid="travel-host">
      {/* Two soft glows behind the cards, the brand orange and blue. */}
      <span aria-hidden className="pointer-events-none absolute -top-40 -left-32 -z-10 size-120 rounded-pill bg-orange-bright/15 blur-3xl" />
      <span aria-hidden className="pointer-events-none absolute -right-32 -bottom-48 -z-10 size-130 rounded-pill bg-blue/22 blur-3xl" />

      <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
        <div className="flex flex-col gap-4">
          <SectionHeading heading={t('sections.creator.heading', { name: creator.name })} lede={t('sections.creator.lede', { name: creator.name })} tone="dark" />
          <p data-reveal className="max-w-3xl text-17 leading-1.65 text-on-navy">
            <strong className="font-semibold text-white">{t('creator.role')}</strong>
            {creator.bio ? <> · {creator.bio}</> : null}
          </p>
        </div>

        <div className="grid-auto-fit-280 grid gap-5">
          {facebook ? (
            <ProfileCard
              locale={locale}
              platform="facebook"
              label={t('creator.facebook')}
              url={facebook.url}
              name={creator.name}
              photo={facebook.photo}
              cover={facebook.cover}
              stats={[{ value: facebook.followers, label: t('creator.followers') }]}
              cta={t('creator.follow')}
            />
          ) : null}
          {youtube ? (
            <ProfileCard
              locale={locale}
              platform="youtube"
              label={t('creator.youtube')}
              url={youtube.url}
              name={creator.name}
              photo={youtube.photo}
              cover={youtube.cover}
              stats={[
                { value: youtube.subscribers, label: t('creator.subscribers') },
                { value: youtube.videoCount, label: t('creator.videos') },
              ]}
              cta={t('creator.subscribe')}
            />
          ) : null}
        </div>

        {creator.videos.length > 0 ? (
          <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <h3 data-reveal className="text-22 font-semibold">
                {t('creator.videosHeading')}
              </h3>
              {youtube ? (
                <a href={youtube.url} target="_blank" rel="noopener noreferrer" className="text-14 font-semibold text-orange-soft hover:underline">
                  {t('creator.moreVideos')} ↗
                </a>
              ) : null}
            </div>
            {/* One row that scrolls sideways on a phone; two, then four, across on wider screens. */}
            <ul className="-mx-section-x flex snap-x scroll-px-section-x gap-4 overflow-x-auto px-section-x pb-2 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 md:grid-cols-4" data-testid="creator-videos">
              {creator.videos.map((video) => (
                <li key={video.youtubeId} data-reveal className="w-72 shrink-0 snap-start sm:w-auto">
                  <a href={video.url} target="_blank" rel="noopener noreferrer" aria-label={t('creator.watch', { title: video.title })} className="group flex flex-col gap-2.5">
                    <span className="relative block aspect-video overflow-hidden rounded-16 bg-navy-mid ring-1 ring-white/10 transition duration-300 ease-lift group-hover:-translate-y-1 group-hover:ring-youtube/70">
                      <Image src={video.thumbnail} alt="" fill sizes="(min-width: 900px) 280px, (min-width: 640px) 45vw, 288px" className="object-cover transition-transform duration-500 ease-lift group-hover:scale-106" />
                      <span aria-hidden className="absolute inset-0 bg-linear-to-t from-ink-deep/65 via-transparent to-transparent" />
                      <span aria-hidden className="absolute top-1/2 left-1/2 flex size-13 -translate-1/2 items-center justify-center rounded-pill bg-youtube text-white shadow-raised transition-transform duration-300 ease-lift group-hover:scale-112">
                        <svg viewBox="0 0 24 24" className="ml-0.5 size-5 fill-current">
                          <path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.02-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" />
                        </svg>
                      </span>
                    </span>
                    <span className="line-clamp-2 text-15 leading-1.45 font-medium text-white/85 transition-colors group-hover:text-white">{video.title}</span>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </section>
  );
}

function ProfileCard({
  locale,
  platform,
  label,
  url,
  name,
  photo,
  cover,
  stats,
  cta,
}: {
  locale: AppLocale;
  platform: Platform;
  label: string;
  url: string;
  name: string;
  photo: ImageView | null;
  cover: ImageView | null;
  stats: { value: number | null; label: string }[];
  cta: string;
}) {
  const { Glyph, fill, cover: coverTone, ring, border } = PLATFORM[platform];
  const shown = stats.filter((stat): stat is { value: number; label: string } => stat.value !== null);
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0] ?? '')
    .join('');

  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      data-reveal
      data-testid={`travel-host-${platform}`}
      className={`group flex flex-col overflow-hidden rounded-22 border border-white/12 bg-white/6 text-white no-underline transition duration-300 ease-lift hover:-translate-y-1.5 hover:bg-white/10 hover:text-white hover:shadow-panel ${border}`}
    >
      {/* Wide, so a channel banner (about 6:1) still shows its middle rather than a sliver of slogan. */}
      <span className={`relative block aspect-16/5 overflow-hidden bg-linear-135/srgb ${coverTone}`}>
        {cover ? (
          <Image src={cover.url} alt="" fill sizes="(min-width: 1200px) 600px, 92vw" className="object-cover transition-transform duration-700 ease-lift group-hover:scale-105" />
        ) : (
          <Glyph className="absolute right-6 -bottom-6 size-36 text-white/12" />
        )}
        <span aria-hidden className="absolute inset-x-0 bottom-0 h-20 bg-linear-to-t from-ink-deep/80 to-transparent" />
        <span className={`absolute top-4 left-4 flex items-center gap-1.5 rounded-pill px-3 py-1.25 text-12 font-bold text-white shadow-raised ${fill}`}>
          <Glyph className="size-3.5" />
          {label}
        </span>
      </span>

      <span className="flex flex-1 flex-col gap-4 px-5.5 pb-5.5">
        {/* Only the photo reaches up into the cover; the name sits below its edge. */}
        <span className="flex items-start gap-4">
          <span className="relative -mt-11 block size-22 shrink-0">
            <span aria-hidden className={`absolute inset-0 rounded-pill bg-conic/srgb motion-safe:animate-ring-spin ${ring}`} />
            <span className="absolute inset-1 flex items-center justify-center overflow-hidden rounded-pill border-3 border-ink-deep bg-navy-mid font-display text-22 font-bold">
              {photo ? <Image src={photo.url} alt="" fill sizes="88px" className="object-cover" /> : initials}
            </span>
          </span>
          <span className="flex min-w-0 flex-col pt-2.5">
            <span className="truncate text-19 font-semibold text-white">{name}</span>
            <span className="truncate text-13 text-on-navy">{bare(url)}</span>
          </span>
        </span>

        {shown.length > 0 ? (
          <span className="flex flex-wrap gap-x-7 gap-y-2">
            {shown.map((stat) => (
              <span key={stat.label} className="flex flex-col">
                <CountUp to={stat.value} format="audience" className="font-display text-fluid-30-42 leading-1 font-extrabold text-white" />
                {/* Spaced capitals suit Latin labels; letter-spacing pulls Bangla conjuncts apart. */}
                <span className={`mt-1 text-12 font-semibold text-on-navy ${locale === 'en' ? 'tracking-label uppercase' : ''}`}>{stat.label}</span>
              </span>
            ))}
          </span>
        ) : null}

        <span className={`mt-auto inline-flex items-center gap-2 self-start rounded-pill px-4.5 py-2.5 text-14 font-bold text-white shadow-raised transition-all duration-300 group-hover:gap-3 ${fill}`}>
          <Glyph className="size-4" />
          {cta}
          <span aria-hidden>→</span>
        </span>
      </span>
    </a>
  );
}
