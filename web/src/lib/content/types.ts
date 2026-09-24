/**
 * Content as the API serves it: every user-facing string in both languages.
 * Pages turn this into single-language views (see views.ts) before it reaches components.
 */
import type { Addon, PriceGrid, PricingConfig } from '@bhabaghure/pricing';

export type Localized = { bn: string; en: string };

export interface Destination {
  slug: string;
  name: Localized;
  countryCode: string | null;
  region: 'international' | 'domestic';
  /** Travellers get the visa on arrival (set in the CMS). */
  visaOnArrival?: boolean;
}

export interface ContentImage {
  url: string;
  alt: Localized;
  credit: string | null;
  creditUrl: string | null;
  /** Stock placeholder the client still has to replace. */
  isPlaceholder: boolean;
}

export interface TourPackage {
  code: string;
  slug: string;
  wpTripId: number | null;
  destination: string;
  status: 'draft' | 'published' | 'archived';
  title: Localized;
  summary: Localized;
  durationDays: number;
  durationNights: number | null;
  regularPrice: number;
  salePrice: number | null;
  /** Hotel-category × traveller prices (Phase 8 §4.D); absent or null: one price and the group discounts. */
  priceGrid?: PriceGrid | null;
  /**
   * What the package costs on top of its own price (docs/package-price-options.md): an extra with an amount per person
   * (a domestic flight), or a cost the agency can only estimate (the international air ticket). Absent: none.
   */
  priceOptions?: PackagePriceOption[];
  /** null = not stated; the card says "ask us". */
  includesAirfare: boolean | null;
  groupMode: 'group' | 'any';
  minPax: number | null;
  departureMode: 'regular' | 'any_date' | 'on_request';
  itinerary: { day: number; title: Localized; body: Localized }[];
  includes: Localized[];
  excludes: Localized[];
  activities: string[];
  tripTypes: string[];
  images: (Omit<ContentImage, 'credit' | 'creditUrl'> & { credit?: string | null; creditUrl?: string | null })[];
}

/** One of extraPerPerson and estimate is set, never both. */
export interface PackagePriceOption {
  label: Localized;
  extraPerPerson: number | null;
  estimate: Localized | null;
}

export interface Departure {
  packageCode: string;
  dateLabel: Localized | null;
  departsOn: string | null;
  seatsTotal: number;
  seatsBooked: number;
  isGuaranteed: boolean;
}

export interface BlogCategory {
  slug: string;
  name: Localized;
  tone: 'blue' | 'purple' | 'orange';
}

export interface BlogPost {
  slug: string;
  category: string;
  publishedAt: string;
  title: Localized;
  excerpt: Localized;
  /** Server-sanitised HTML. */
  body: Localized;
  author: Localized;
  readingMinutes: number;
  status: 'draft' | 'published';
}

export interface TeamMember {
  employeeCode: string;
  name: Localized;
  role: Localized;
  photo: ContentImage | null;
  sortOrder: number;
  isVisible: boolean;
}

export interface Review {
  quote: Localized;
  reviewerName: string;
  tripLabel: Localized;
  rating: number;
}

export interface GalleryItem {
  kind: 'reel' | 'photo';
  url: string;
  viewsThousands: number | null;
  caption: Localized | null;
  thumbnail?: ContentImage | null;
}

/** A visa the agency processes (docs/phase-8-visa-quotes-pricing-downloads.md §4.C), from Admin → Visa services. */
export interface VisaService {
  slug: string;
  /** ISO 3166-1 alpha-2, for the flag. */
  countryCode: string | null;
  country: Localized;
  visaType: Localized;
  /** Per person in taka; null: priced on request. */
  price: number | null;
  processing: Localized | null;
  stay: Localized | null;
  requirements: { bn: string[]; en: string[] };
  notes: Localized | null;
  updatedAt: string | null;
}

/** The travel host on the home page (docs/travel-host.md), from Admin → Travel host. */
export interface CreatorProfile {
  name: Localized;
  bio: Localized | null;
  facebook: { url: string; followers: number | null; photo: ContentImage | null; cover: ContentImage | null } | null;
  youtube: { url: string; subscribers: number | null; videoCount: number | null; photo: ContentImage | null; cover: ContentImage | null } | null;
}

export interface CreatorVideo {
  youtubeId: string;
  url: string;
  title: Localized;
  /** YouTube's still, i.ytimg.com. */
  thumbnail: string;
}

export interface Creator {
  /** Null until a profile with a link is saved: the section stays hidden. */
  profile: CreatorProfile | null;
  videos: CreatorVideo[];
}

/** An offer banner in the slideshow under the hero video (docs/offer-banners.md). */
export interface OfferBanner {
  title: Localized;
  image: ContentImage | null;
  /** A page on this site ("/packages/…") or a full address; null when the banner leads nowhere. */
  linkUrl: string | null;
}

/** A photo in the group tour gallery (docs/group-tour-gallery.md). */
export interface TourPhoto {
  /** The trip it shows: "Mustang, Nepal". */
  caption: Localized;
  /** "2026-09", or null when the month isn't known. */
  month: string | null;
  image: ContentImage | null;
  /** The tour, while its package is on the website ("See this tour"). */
  package: { slug: string; title: Localized } | null;
}

/** An airline the agency books, shown as a logo above the footer (docs/partners-and-payments.md). */
export interface AirlinePartner {
  name: Localized;
  logo: ContentImage | null;
  websiteUrl: string | null;
}

export interface PricingSettings extends PricingConfig {
  addons: (Addon & { name: Localized })[];
  /**
   * Whether the built-in SSLCommerz checkout is live (Phase 8 §4.F). Off, the booking form saves the booking and its page
   * shows how to pay by hand. Absent (an API from before §4.F, or the seed): off.
   */
  onlineCheckout?: boolean;
  /**
   * A code to the lead traveller's mobile before the booking is saved (docs/booking-phone-verification.md). Absent (an
   * older API, or the seed): off.
   */
  verifyPhone?: boolean;
}

export interface SiteSettings {
  company: { name: Localized; brand: Localized };
  contact: {
    phone: string;
    phoneAlt: string;
    whatsapp: string;
    /** The dedicated number automated WhatsApp messages come from; empty until the client connects one. */
    notificationsWhatsapp?: string | null;
    email: string;
    facebook: string;
    instagram: string;
    website: string;
  };
  address: string;
  civilAviationNo: string;
  hours: { opens: number; closes: number };
  stats: { topReelViewsThousands: number; banglaSupportPercent: number };
}

export interface ContentBundle {
  destinations: Destination[];
  packages: TourPackage[];
  departures: Departure[];
  categories: BlogCategory[];
  posts: BlogPost[];
  team: TeamMember[];
  reviews: Review[];
  gallery: GalleryItem[];
  visas: VisaService[];
  creator: Creator;
  partners: AirlinePartner[];
  offers: OfferBanner[];
  tourPhotos: TourPhoto[];
  pricing: PricingSettings;
  settings: SiteSettings;
}
