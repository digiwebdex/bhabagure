'use client';

import { usePathname } from 'next/navigation';
import { useEffect } from 'react';

import { heartbeat, trackPageView } from '@/lib/analytics';

/** Beat this often while the tab is visible (docs/visitor-analytics.md): "live" and time on site come from it. */
const BEAT_SECONDS = 30;

/**
 * Website pages only (it sits in SiteChrome; the portal and the admin never have it): a page view on every page,
 * including in-app navigation, and the visible tab's heartbeat. Renders nothing and never blocks the page.
 */
export function VisitorAnalytics() {
  const pathname = usePathname();

  useEffect(() => {
    trackPageView(window.location.pathname);
  }, [pathname]);

  useEffect(() => {
    let since = Date.now();
    const beat = () => {
      const seconds = (Date.now() - since) / 1000;
      since = Date.now();
      heartbeat(seconds);
    };
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') beat();
      else since = Date.now();
    }, BEAT_SECONDS * 1000);
    // Hidden or closing: count the visible time since the last beat; coming back starts a fresh count.
    const onVisibility = () => (document.visibilityState === 'hidden' ? beat() : (since = Date.now()));
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('pagehide', beat);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('pagehide', beat);
    };
  }, []);

  return null;
}
