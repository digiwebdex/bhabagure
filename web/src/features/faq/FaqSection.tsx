import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import { formattersFor } from '@/lib/formatters';

import { FaqList } from './FaqList';

/** `{percent}` in the FAQ copy is the single-room supplement from the Pricing screen, so the answer can't go stale. */
export function fillFaq(items: { q: string; a: string }[], supplementText: string) {
  return items.map((item) => ({ q: item.q, a: item.a.replaceAll('{percent}', supplementText) }));
}

export async function FaqSection({ locale, singleRoomSupplementPercent }: { locale: AppLocale; singleRoomSupplementPercent: number }) {
  const t = await getTranslations({ locale });
  const items = fillFaq(t.raw('faq') as { q: string; a: string }[], formattersFor(locale).percent(singleRoomSupplementPercent));

  return (
    <section id="faq" className="border-t border-hairline bg-orange-wash">
      <div className="mx-auto flex max-w-narrow flex-col gap-7 px-section-x py-section-y">
        <SectionHeading heading={t('sections.faq.heading')} lede={t('sections.faq.lede')} />
        <FaqList items={items} />
      </div>
    </section>
  );
}
