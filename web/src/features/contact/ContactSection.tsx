import { getTranslations } from 'next-intl/server';
import type { ReactNode } from 'react';

import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { displayPhone, telUrl } from '@/lib/links';

import { InquiryForm } from './InquiryForm';

/** Phone, email, hours, Facebook and website beside the inquiry form, on the blue-to-rust gradient. */
export async function ContactSection({ locale, settings }: { locale: AppLocale; settings: SiteViews['settings'] }) {
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  const { contact, hours } = settings;
  const bare = (url: string) => url.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');

  const row = (label: string, value: ReactNode) => (
    <div>
      <span className="block text-13 opacity-70">{label}</span>
      {value}
    </div>
  );

  return (
    <section id="contact" className="bg-linear-135/srgb from-blue-deep via-blue-ink via-60% to-rust text-white">
      <div className="mx-auto grid-auto-fit-300 grid max-w-site items-start gap-fluid-28-40 px-section-x py-section-y">
        <div className="flex flex-col gap-4.5">
          <div className="flex flex-col">
            <span aria-hidden className="mb-4 h-0.5 w-7 bg-orange-deep" />
            <h2 className="text-section">{t('sections.contact.heading')}</h2>
          </div>
          {t('sections.contact.lede') ? <p className="font-display text-18 opacity-90">{t('sections.contact.lede')}</p> : null}
          <div className="flex flex-col gap-3 text-16">
            {row(
              t('contact.phone'),
              <a href={telUrl(contact.phone)} className="font-display text-fluid-19-22 font-semibold break-words text-orange-light hover:text-white">
                {f.digits(displayPhone(contact.phone))}
              </a>,
            )}
            {/* Published beside the main line so a customer can check who an automated WhatsApp message is from. */}
            {contact.notificationsWhatsapp
              ? row(
                  t('contact.notifications'),
                  <>
                    <span className="block font-display text-18 font-semibold break-words" data-testid="notifications-number">
                      {f.digits(displayPhone(contact.notificationsWhatsapp))}
                    </span>
                    <span className="block text-13 opacity-75">{t('contact.notificationsNote')}</span>
                  </>,
                )
              : null}
            {row(
              t('contact.email'),
              <a href={`mailto:${contact.email}`} className="text-white hover:text-orange-light">
                {contact.email}
              </a>,
            )}
            {row(t('contact.hours'), t('contact.hoursValue', { open: f.number(hours.opens), close: f.number(hours.closes - 12) }))}
            {row(
              t('contact.facebook'),
              <a href={contact.facebook} target="_blank" rel="noopener noreferrer" className="text-white hover:text-orange-light">
                {bare(contact.facebook)}
              </a>,
            )}
            {row(
              t('contact.website'),
              <a href={contact.website} className="text-white hover:text-orange-light">
                {bare(contact.website)}
              </a>,
            )}
          </div>
        </div>
        <InquiryForm />
      </div>
    </section>
  );
}
