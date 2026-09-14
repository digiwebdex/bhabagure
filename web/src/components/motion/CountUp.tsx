'use client';

import { useEffect, useRef } from 'react';

import { useFormatters } from '@/lib/use-formatters';

interface CountUpProps {
  to: number;
  suffix?: string;
  className?: string;
}

const DURATION_MS = 1100;

/**
 * Trust stats animate from 0 when scrolled into view (ease-out cubic, 1.1s). The server renders
 * the final number, so it is correct without JavaScript and for search engines. Every frame is
 * formatted by the shared formatter — Bengali digits in Bangla, Latin in English.
 */
export function CountUp({ to, suffix = '', className }: CountUpProps) {
  const { number } = useFormatters();
  const ref = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;
    if (el.getBoundingClientRect().top < window.innerHeight) return; // already on screen: keep the final value

    let frame = 0;
    el.textContent = number(0) + suffix;
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry?.isIntersecting) return;
        observer.disconnect();
        const start = performance.now();
        const tick = (now: number) => {
          const progress = Math.min((now - start) / DURATION_MS, 1);
          const eased = 1 - (1 - progress) ** 3;
          el.textContent = number(Math.round(to * eased)) + suffix;
          if (progress < 1) frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
      },
      { threshold: 0.4 },
    );
    observer.observe(el);

    return () => {
      observer.disconnect();
      cancelAnimationFrame(frame);
      el.textContent = number(to) + suffix;
    };
  }, [to, suffix, number]);

  return (
    <span ref={ref} className={className}>
      {number(to) + suffix}
    </span>
  );
}
