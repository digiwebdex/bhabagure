import type { PackageView, PostView, SiteViews } from '@/lib/content/views';

/** schema.org JSON-LD. Rendered as <script type="application/ld+json"> with `<` escaped. */
export function jsonLd(data: Record<string, unknown>): string {
  return JSON.stringify({ '@context': 'https://schema.org', ...data }).replace(/</g, '\\u003c');
}

export function travelAgency(settings: SiteViews['settings'], origin: string) {
  return {
    '@type': 'TravelAgency',
    name: settings.company.name.en,
    alternateName: settings.company.name.bn,
    url: origin,
    telephone: settings.contact.phone,
    email: settings.contact.email,
    address: { '@type': 'PostalAddress', streetAddress: settings.address, addressLocality: 'Dhaka', addressCountry: 'BD' },
    sameAs: [settings.contact.facebook, settings.contact.instagram],
    openingHoursSpecification: {
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
      opens: `${String(settings.hours.opens).padStart(2, '0')}:00`,
      closes: `${String(settings.hours.closes).padStart(2, '0')}:00`,
    },
  };
}

export function touristTrip(pkg: PackageView, url: string, settings: SiteViews['settings']) {
  return {
    '@type': 'TouristTrip',
    name: pkg.title,
    description: pkg.summary,
    url,
    image: pkg.images.map((img) => img.url),
    itinerary: {
      '@type': 'ItemList',
      itemListElement: pkg.itinerary.map((day) => ({ '@type': 'ListItem', position: day.day, name: day.title, description: day.body })),
    },
    offers: {
      '@type': 'Offer',
      price: pkg.listPrice,
      priceCurrency: 'BDT',
      availability: 'https://schema.org/InStock',
      seller: { '@type': 'TravelAgency', name: settings.company.name.en },
    },
  };
}

export function blogPosting(post: PostView, url: string, settings: SiteViews['settings']) {
  return {
    '@type': 'BlogPosting',
    headline: post.title,
    description: post.excerpt,
    datePublished: post.publishedAt,
    url,
    author: { '@type': 'Organization', name: post.author },
    publisher: { '@type': 'Organization', name: settings.company.name.en },
  };
}
