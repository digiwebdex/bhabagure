import type { PriceGrid } from '@bhabaghure/pricing'

/** Shapes returned by the staff API (api/app/Http/Resources/AdminContent.php). Money is a number. */

export type Data<T> = { data: T }

export type Staff = {
  id: number
  employee_code: string
  name: string
  email: string
  status: 'invited' | 'active' | 'suspended'
  locale: 'bn' | 'en'
  must_change_password: boolean
  role: string | null
  /** A custom role's own name; system roles are labelled from the admin's translations. */
  role_name_en: string | null
  role_name_bn: string | null
  is_super_admin: boolean
  permissions: string[]
}

export type TokenResponse = { access_token: string; expires_in: number; staff: Staff }

export type MediaVariant = { url: string; width: number; height: number }

export type Media = {
  id: number
  url: string | null
  variants: Partial<Record<'thumb' | 'card' | 'detail' | 'full', MediaVariant>>
  original_filename: string | null
  mime: string
  bytes: number
  width: number | null
  height: number | null
  alt_bn: string | null
  alt_en: string | null
  credit: string | null
  credit_url: string | null
  is_placeholder: boolean
  created_at: string | null
}

export type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } }

export type Destination = {
  id: number
  slug: string
  name_bn: string
  name_en: string
  country_code: string | null
  region: 'international' | 'domestic'
  /** Travellers get the visa on arrival: their visa and insurance default to not required. */
  visa_on_arrival: boolean
  sort_order: number
  packages_count?: number
}

export type PackageStatus = 'draft' | 'published' | 'archived'
export type ContentStatus = 'draft' | 'published'

export type PackageSummary = {
  id: number
  code: string
  slug: string
  title_bn: string | null
  title_en: string
  destination: Destination | null
  duration_days: number
  duration_nights: number | null
  regular_price: number
  sale_price: number | null
  /** Hotel-category × traveller prices (Phase 8 §4.D); null: one price and the group discounts. */
  price_grid: PriceGrid | null
  status: PackageStatus
  published_at: string | null
  is_featured: boolean
  sort_order: number
  missing_bangla: boolean
  cover: Media | null
  updated_at: string | null
}

export type ItineraryDay = { day_number: number; title_bn: string | null; title_en: string | null; body_bn: string | null; body_en: string }
export type Inclusion = { text_bn: string | null; text_en: string }
export type PackageImage = { id: number; sort_order: number; is_cover: boolean; media: Media }

export type TourPackage = PackageSummary & {
  wp_trip_id: number | null
  destination_id: number
  summary_bn: string | null
  summary_en: string | null
  includes_airfare: boolean | null
  group_mode: 'group' | 'any'
  min_pax: number | null
  departure_mode: 'regular' | 'any_date' | 'on_request'
  difficulty: string | null
  seo_title_bn: string | null
  seo_title_en: string | null
  seo_description_bn: string | null
  seo_description_en: string | null
  itinerary: ItineraryDay[]
  includes: Inclusion[]
  excludes: Inclusion[]
  activities: string[]
  trip_types: string[]
  images: PackageImage[]
}

export type Departure = {
  id: number
  tour_package_id: number
  departs_on: string
  returns_on: string | null
  seats_total: number
  seats_booked: number
  is_guaranteed: boolean
  status: 'scheduled' | 'closed' | 'departed' | 'cancelled'
  group_leader_staff_id: number | null
  notes: string | null
}

export type Tag = { id: number; type: 'activity' | 'trip_type'; slug: string; name_en: string; name_bn: string | null }

export type BlogCategory = { id: number; slug: string; name_bn: string; name_en: string; tone: 'blue' | 'purple' | 'orange'; sort_order: number; posts_count?: number }

export type BlogPost = {
  id: number
  slug: string
  blog_category_id: number
  category: BlogCategory | null
  title_bn: string
  title_en: string
  excerpt_bn: string | null
  excerpt_en: string | null
  body_bn?: string | null
  body_en?: string | null
  author_bn: string | null
  author_en: string | null
  cover: Media | null
  cover_media_id: number | null
  reading_minutes: number
  reading_minutes_override: boolean
  seo_title_bn: string | null
  seo_title_en: string | null
  seo_description_bn: string | null
  seo_description_en: string | null
  status: ContentStatus
  published_at: string | null
  is_scheduled: boolean
  updated_at: string | null
}

export type TeamMember = {
  id: number
  name_bn: string
  name_en: string
  role_bn: string
  role_en: string
  employee_code: string | null
  photo_media_id: number | null
  staff_id: number | null
  sort_order: number
  status: ContentStatus
  photo: Media | null
}

export type Review = {
  id: number
  quote_bn: string
  quote_en: string | null
  reviewer_name: string
  trip_label_bn: string | null
  trip_label_en: string | null
  rating: number
  tour_package_id: number | null
  sort_order: number
  travelled_on: string | null
  status: ContentStatus
}

/** api/app/Http/Resources/AdminContent.php visaService (docs/phase-8-visa-quotes-pricing-downloads.md §4.C). */
export type VisaService = {
  id: number
  slug: string
  country_code: string | null
  country_bn: string
  country_en: string
  visa_type_bn: string
  visa_type_en: string
  /** Per person, in taka; null: priced on request. */
  price: number | null
  processing_bn: string | null
  processing_en: string | null
  stay_bn: string | null
  stay_en: string | null
  /** One requirement per line. */
  requirements_bn: string | null
  requirements_en: string | null
  notes_bn: string | null
  notes_en: string | null
  sort_order: number
  status: ContentStatus
}

export type GalleryItem = {
  id: number
  kind: 'reel' | 'photo'
  url: string
  caption_bn: string | null
  caption_en: string | null
  view_count: number | null
  media_id: number | null
  sort_order: number
  status: ContentStatus
  thumbnail: Media | null
}

/** The travel host on the home page (docs/travel-host.md), as GET and PUT admin/creator. Photos come whole, for the form. */
export type CreatorProfile = {
  name_bn: string
  name_en: string
  bio_bn: string | null
  bio_en: string | null
  facebook_url: string | null
  facebook_followers: number | null
  facebook_photo_media_id: number | null
  facebook_photo: Media | null
  facebook_cover_media_id: number | null
  facebook_cover: Media | null
  youtube_url: string | null
  youtube_subscribers: number | null
  youtube_video_count: number | null
  youtube_photo_media_id: number | null
  youtube_photo: Media | null
  youtube_cover_media_id: number | null
  youtube_cover: Media | null
}

export type CreatorVideo = {
  id: number
  youtube_id: string
  /** The watch link, whatever shape of link was pasted. */
  url: string
  thumbnail_url: string
  title_bn: string | null
  title_en: string | null
  sort_order: number
  status: ContentStatus
}

export type Slab = { min_pax: number; discount_percent: number }

export type Pricing = {
  slabs: Slab[]
  single_room_supplement_percent: number
  service_charge_percent: number
  max_travellers: number
  /** 0 = the company absorbs the gateway fee; otherwise shown to the customer as its own line before paying. */
  online_payment_charge_percent: number
}

export type Addon = { id: number; code: string; name_bn: string; name_en: string; price: number; unit: 'per_person' | 'per_booking'; is_active: boolean; sort_order: number }

export type Localized = { bn: string; en: string }

export type SiteSettings = {
  company?: { name: Localized; brand: Localized }
  contact?: {
    phone: string
    phoneAlt: string | null
    whatsapp: string
    /** The dedicated number automated WhatsApp messages come from, published beside the main line. */
    notificationsWhatsapp?: string | null
    email: string
    facebook: string | null
    instagram: string | null
    website: string | null
  }
  address?: string
  civilAviationNo?: string
  hours?: { opens: number; closes: number }
  stats?: { topReelViewsThousands: number; banglaSupportPercent: number }
  /** How customers pay by hand (docs/phase-8-visa-quotes-pricing-downloads.md §4.F). Each method is optional. */
  payment?: {
    bank: { bankName: string; accountName: string; accountNumber: string; branch: string; routingNumber: string; transferType: string } | null
    /** A hosted SSLCommerz payment form, shown until the built-in checkout takes payments. */
    link: string | null
    bkash: { number: string; chargePercent: number } | null
  }
}
