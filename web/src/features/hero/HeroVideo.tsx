'use client';

import { useEffect, useRef } from 'react';

type NetworkInformation = { saveData?: boolean };

/**
 * Muted looping hero video over its poster. It only starts playing when the visitor hasn't asked
 * for reduced motion or reduced data — the built-in file is 5.4 MB and an uploaded one up to 20 MB,
 * heavy on Bangladeshi mobile plans — so those visitors see the poster and download nothing more.
 * Without a poster (a linked video may have none) the hero's dark background shows until it plays.
 */
export function HeroVideo({ src, poster }: { src: string; poster: string | null }) {
  const video = useRef<HTMLVideoElement>(null);

  useEffect(() => {
    const el = video.current;
    if (!el) return;
    const saveData = (navigator as Navigator & { connection?: NetworkInformation }).connection?.saveData === true;
    if (saveData || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    el.muted = true;
    el.preload = 'auto';
    el.play().catch(() => {
      // Autoplay refused (e.g. low-power mode): the poster stays.
    });
  }, [src]);

  return (
    <video
      key={src}
      ref={video}
      aria-hidden
      muted
      loop
      playsInline
      preload="none"
      poster={poster ?? undefined}
      src={src}
      className="absolute inset-0 size-full object-cover"
    />
  );
}
