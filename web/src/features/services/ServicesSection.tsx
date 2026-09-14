import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import { formattersFor } from '@/lib/formatters';

type Service = { title: string; subtitle: string; desc: string };

/** Six service cards: tour packages, air tickets, hotels, visa, corporate travel, 24/7 support. */
export async function ServicesSection({ locale }: { locale: AppLocale }) {
  const t = await getTranslations({ locale });
  const services = t.raw('services') as Service[];
  const { digits } = formattersFor(locale);

  return (
    <section id="services" className="mx-auto w-full max-w-site px-section-x py-section-y">
      <SectionHeading heading={t('sections.services.heading')} lede={t('sections.services.lede')} className="mb-9" />
      <div className="grid-auto-fit-240 grid gap-4.5">
        {services.map((service, i) => (
          <div
            key={service.title}
            data-reveal
            className="flex flex-col gap-2 rounded-20 border border-hairline bg-white p-6.5 transition duration-250 ease-lift hover:-translate-y-1.5 hover:border-hairline-hover hover:shadow-card"
          >
            <span className="mb-1.5 flex size-10 items-center justify-center rounded-12 bg-linear-135/srgb from-blue to-blue-deep font-display text-14 font-bold text-white">
              {digits(String(i + 1).padStart(2, '0'))}
            </span>
            <h3 className="text-22 font-semibold">{service.title}</h3>
            {service.subtitle ? <p className="font-display text-16 font-semibold text-blue">{service.subtitle}</p> : null}
            <p className="mt-1 text-15 leading-1.65 text-muted">{service.desc}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
