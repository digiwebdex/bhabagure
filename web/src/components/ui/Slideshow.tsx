'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * One slide at a time in a scroll-snapping track — the offer banners (docs/offer-banners.md) and the group tour gallery
 * (docs/group-tour-gallery.md). It moves on by itself every `everyMs`, and waits while someone is pointing at it, using
 * its buttons or reading another tab; for a visitor who asks for reduced motion it never moves on its own, and jumps
 * rather than glides when asked. The scroll position is the truth: a swipe, a button and the timer all end up there, so
 * `current` always matches what is on screen.
 */
export function useSlideshow(count: number, everyMs: number) {
  const track = useRef<HTMLUListElement>(null);
  const [current, setCurrent] = useState(0);
  const [held, setHeld] = useState(false);

  const show = useCallback((index: number) => {
    const list = track.current;
    if (!list) return;
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    list.scrollTo({ left: index * list.clientWidth, behavior: still ? 'auto' : 'smooth' });
  }, []);

  useEffect(() => {
    const list = track.current;
    if (!list) return;
    const onScroll = () => setCurrent(Math.round(list.scrollLeft / Math.max(list.clientWidth, 1)));
    list.addEventListener('scroll', onScroll, { passive: true });
    return () => list.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    if (count < 2 || held) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return; // nobody is looking
      const list = track.current;
      if (!list) return;
      const at = Math.round(list.scrollLeft / Math.max(list.clientWidth, 1));
      show((at + 1) % count);
    }, everyMs);
    return () => window.clearInterval(timer);
  }, [count, everyMs, held, show]);

  return {
    track,
    current,
    show,
    /** The next or the previous slide, round the ends. */
    step: (by: 1 | -1) => show((current + by + count) % count),
    /** For the slideshow's outer element: pointing at it, or focus anywhere inside it, holds it still. */
    holdProps: {
      onMouseEnter: () => setHeld(true),
      onMouseLeave: () => setHeld(false),
      onFocusCapture: () => setHeld(true),
      onBlurCapture: () => setHeld(false),
    },
  };
}

/** A round previous/next button over the slides; from the `sm` width up unless `onPhones`. */
export function SlideArrow({ side, label, onClick, onPhones = false }: { side: 'left' | 'right'; label: string; onClick: () => void; onPhones?: boolean }) {
  return (
    <button
      type="button"
      aria-label={label}
      onClick={onClick}
      className={`absolute top-1/2 ${onPhones ? 'flex size-9 sm:size-10' : 'hidden size-10 sm:flex'} -translate-y-1/2 cursor-pointer items-center justify-center rounded-pill border border-hairline bg-white/90 text-ink shadow-raised backdrop-blur-sm transition hover:bg-white ${side === 'left' ? 'left-3' : 'right-3'}`}
    >
      <svg viewBox="0 0 24 24" aria-hidden="true" className="size-5 fill-none stroke-current stroke-2">
        <path d={side === 'left' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7'} strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </button>
  );
}
