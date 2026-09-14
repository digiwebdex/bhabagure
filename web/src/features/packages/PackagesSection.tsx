import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';

import { PackageExplorer } from './PackageExplorer';

export async function PackagesSection({ locale }: { locale: AppLocale }) {
  const t = await getTranslations({ locale, namespace: 'sections.packages' });

  return (
    <section id="packages" className="border-y border-hairline-soft bg-paper-alt">
      <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
        <PackageExplorer heading={<SectionHeading heading={t('heading')} lede={t('lede')} />} />
      </div>
    </section>
  );
}
