import type { SiteViews } from '@/lib/content/views';
import type { Formatters } from '@/lib/formatters';
import { displayPhone } from '@/lib/links';

type Translate = (key: string, values?: Record<string, string | number>) => string;

export type Intent = 'destination' | 'prices' | 'visa' | 'booking';

/**
 * Rule-based assistant. Every answer is built from live content — package titles, durations and
 * prices come from the catalogue, never from copy — so it can't quote a package or price that
 * doesn't exist (the prototype's replies hard-coded Maldives, Sri Lanka and Kashmir prices).
 */
const DESTINATION_KEYWORDS: Record<string, string[]> = {
  nepal: ['kathmandu', 'pokhara', 'mustang', 'nagarkot', 'কাঠমান্ডু', 'পোখারা', 'নাগরকোট', 'মুস্তাং'],
  thailand: ['thai', 'bangkok', 'pattaya', 'phuket', 'থাই', 'ব্যাংকক', 'পাত্তায়া', 'ফুকেট'],
  maldives: ['মালে'],
  'sri-lanka': ['srilanka', 'colombo', 'কলম্বো'],
  philippines: ['manila', 'ম্যানিলা'],
};

export interface AssistantContext {
  views: Pick<SiteViews, 'packages' | 'allDestinations' | 'settings'>;
  t: Translate; // "assistant" namespace
  tp: Translate; // "packages" namespace
  faqVisaAnswer: string;
  f: Formatters;
}

export function findDestination(question: string, views: AssistantContext['views']): string | null {
  const q = question.toLowerCase();
  for (const d of views.allDestinations) {
    const words = [d.label.toLowerCase(), d.name.toLowerCase(), ...(DESTINATION_KEYWORDS[d.slug] ?? [])];
    if (words.some((w) => w && q.includes(w))) return d.slug;
  }
  return null;
}

export function answerQuestion(question: string, ctx: AssistantContext): string {
  const { views, t, f } = ctx;
  const q = question.toLowerCase();

  const destination = findDestination(q, views);
  if (destination) return destinationAnswer(destination, ctx);
  if (/price|cost|budget|দাম|খরচ|বাজেট|কত/.test(q)) return pricesAnswer(ctx);
  if (/visa|ভিসা/.test(q)) return ctx.faqVisaAnswer;
  if (/book|pay|বুক|পেমেন্ট|কীভাবে/.test(q)) return t('howToBook');

  return t('fallback', {
    phone: f.digits(displayPhone(views.settings.contact.phone)),
    open: f.number(views.settings.hours.opens),
    close: f.number(views.settings.hours.closes - 12),
  });
}

export function destinationAnswer(slug: string, { views, t, tp, f }: AssistantContext): string {
  const destination = views.allDestinations.find((d) => d.slug === slug);
  const name = destination?.name ?? slug;
  const matches = views.packages.filter((p) => p.destinationSlug === slug);
  if (matches.length === 0) return t('noPackages', { destination: name });

  const list = matches
    .map((p) => {
      const duration =
        p.durationNights != null
          ? tp('durationNights', { nightsText: f.number(p.durationNights), daysText: f.number(p.durationDays) })
          : tp('durationDays', { days: p.durationDays, daysText: f.number(p.durationDays) });
      return t('packageLine', { title: p.title, duration, price: f.bdt(p.listPrice), air: p.includesAirfare ? t('withAir') : '' });
    })
    .join(f.locale === 'bn' ? ', ' : '; ');
  return t('destinationPackages', { destination: name, count: matches.length, countText: f.number(matches.length), list });
}

export function pricesAnswer({ views, t, f }: AssistantContext): string {
  if (views.packages.length === 0) return t('howToBook');
  const prices = views.packages.map((p) => p.listPrice);
  let text = t('prices', { min: f.bdt(Math.min(...prices)), max: f.bdt(Math.max(...prices)) });
  const offers = views.packages.filter((p) => p.savingPercent > 0);
  if (offers.length > 0) {
    const list = offers.map((p) => t('discountLine', { title: p.title, percentText: f.number(p.savingPercent) })).join(', ');
    text += t('discounts', { list });
  }
  return text;
}
