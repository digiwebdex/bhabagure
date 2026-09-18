import type { Metadata } from 'next';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { AboutSection } from '@/features/about/AboutSection';
import { BlogSection } from '@/features/blog/BlogSection';
import { ContactSection } from '@/features/contact/ContactSection';
import { CreatorSection } from '@/features/creator/CreatorSection';
import { DeparturesSection } from '@/features/departures/DeparturesSection';
import { FaqSection } from '@/features/faq/FaqSection';
import { GallerySection } from '@/features/gallery/GallerySection';
import { HeroSection } from '@/features/hero/HeroSection';
import { OffersSlideshow } from '@/features/offers/OffersSlideshow';
import { Marquee } from '@/features/hero/Marquee';
import { StepsSection } from '@/features/how-it-works/StepsSection';
import { PackagesSection } from '@/features/packages/PackagesSection';
import { PartnersSection } from '@/features/partners/PartnersSection';
import { ReviewsSection } from '@/features/reviews/ReviewsSection';
import { SearchPanel } from '@/features/search/SearchPanel';
import { ServicesSection } from '@/features/services/ServicesSection';
import { SiteChrome } from '@/features/SiteChrome';
import { VisaSection } from '@/features/visa/VisaSection';
import { WhyUsSection } from '@/features/why-us/WhyUsSection';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { jsonLd, travelAgency } from '@/lib/structured-data';

export async function generateMetadata({ params }: PageProps<'/[locale]/site'>): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: 'meta' });
  const origin = siteUrl();
  return {
    title: t('siteTitle'),
    description: t('siteDescription'),
    metadataBase: new URL(origin),
    alternates: { canonical: locale === 'bn' ? '/' : '/en', languages: { bn: '/', en: '/en' } },
    openGraph: {
      title: t('siteTitle'),
      description: t('siteDescription'),
      url: locale === 'bn' ? '/' : '/en',
      siteName: 'Bhabaghure Holidays',
      locale: locale === 'bn' ? 'bn_BD' : 'en_US',
      type: 'website',
      images: ['/media/hero-poster.jpg'],
    },
  };
}

/** Home page. Sections follow the agreed order (docs/phase-2-website.md, decision 1). */
export default async function SiteHome({ params }: PageProps<'/[locale]/site'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname="/">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(travelAgency(views.settings, siteUrl())) }} />
      <HeroSection locale={locale} />
      <Marquee locale={locale} />
      <SearchPanel />
      <OffersSlideshow offers={views.offers} />
      <ServicesSection locale={locale} />
      <PackagesSection locale={locale} />
      <DeparturesSection locale={locale} departures={views.departures} />
      <VisaSection locale={locale} views={views} />
      <WhyUsSection locale={locale} stats={views.stats} settings={views.settings} />
      <StepsSection locale={locale} />
      <ReviewsSection locale={locale} reviews={views.reviews} />
      <FaqSection locale={locale} singleRoomSupplementPercent={views.pricing.singleRoomSupplementPercent} />
      <GallerySection locale={locale} items={views.gallery} facebook={views.settings.contact.facebook} />
      <AboutSection locale={locale} team={views.team} stats={views.stats} settings={views.settings} />
      <CreatorSection locale={locale} creator={views.creator} />
      <BlogSection locale={locale} posts={views.posts} categories={views.categories} />
      <ContactSection locale={locale} settings={views.settings} />
      <PartnersSection locale={locale} partners={views.partners} />
    </SiteChrome>
  );
}
