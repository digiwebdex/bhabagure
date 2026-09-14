'use client';

import { useEffect } from 'react';

/**
 * Scroll reveals: every [data-reveal] element below the fold fades up 20px as it enters the
 * viewport, staggered 70ms within each batch (prototype: rootMargin −6%, threshold 0.1).
 *
 * Content is server-rendered visible; elements are only hidden here, after hydration, so nothing
 * disappears without JavaScript and nothing above the fold flickers. Off under reduced motion.
 */
export function RevealObserver() {
  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;

    const fold = window.innerHeight * 0.94;
    const pending = [...document.querySelectorAll<HTMLElement>('[data-reveal]:not([data-revealed])')].filter(
      (el) => el.getBoundingClientRect().top > fold,
    );
    pending.forEach((el) => el.classList.add('reveal-pending'));

    const observer = new IntersectionObserver(
      (entries) => {
        let index = 0;
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const el = entry.target as HTMLElement;
          observer.unobserve(el);
          el.dataset.revealed = '';
          el.style.animationDelay = `${index * 70}ms`;
          el.classList.remove('reveal-pending');
          el.classList.add('animate-reveal');
          index += 1;
        }
      },
      { rootMargin: '0px 0px -6% 0px', threshold: 0.1 },
    );
    pending.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  return null;
}
