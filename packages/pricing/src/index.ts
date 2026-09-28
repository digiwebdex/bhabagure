/**
 * Package pricing — the one implementation behind the package cards, the detail modal and the
 * booking form. The Laravel API repeats it when it creates a booking (Phase 3), held to the same
 * fixtures.json, so the price a traveller sees is the price they are charged.
 *
 * Group slab (Phase 2 decision): 1–2 travellers pay list price; 3 → −3%; 4–5 → −6%;
 * 6–9 → −9%; 10+ → −12%. A single traveller pays list price — the +12% belongs to the
 * single-room choice at booking, never to the slab.
 *
 * All amounts are whole taka. Rounding is half-up per step, matching PHP's round() for
 * positive amounts.
 */

export interface Slab {
  minPax: number;
  discountPercent: number;
}

export interface PricingConfig {
  slabs: Slab[];
  singleRoomSupplementPercent: number;
  serviceChargePercent: number;
  maxTravellers: number;
  /**
   * "Online payment charge" added when paying through SSLCommerz, shown as its own line before the customer commits.
   * 0 = the company absorbs the gateway's fee. Never charged at the gateway on top of what the review step showed.
   */
  onlinePaymentChargePercent: number;
}

export type AddonUnit = 'per_person' | 'per_booking';

export interface Addon {
  code: string;
  price: number;
  unit: AddonUnit;
}

export type RoomType = 'twin' | 'triple' | 'single';

/**
 * Hotel-category price grid (docs/phase-8-visa-quotes-pricing-downloads.md §2.1, §4.D): a package can price each hotel
 * category (basic/3-star, 4-star, 5-star) by group size instead of one price and the site-wide group discounts. Staff
 * enter a per-person price for 1, 2, 4, 6, 8, 10 and 12 travellers (8 and 12 added 2026-09-27,
 * docs/customized-trip-group-sizes.md); a group between tiers pays the tier below (3 → the 2-person price, 5 → the
 * 4-person price), 12 or more the 12-person price. A size left empty falls back to the one below. A category is offered
 * when it has at least the 1-traveller price.
 */
export type HotelCategory = '3' | '4' | '5';
export const HOTEL_CATEGORIES: readonly HotelCategory[] = ['3', '4', '5'];
export const GRID_TIERS = [1, 2, 4, 6, 8, 10, 12] as const;
export type GridTier = (typeof GRID_TIERS)[number];
export type PriceGridRow = Partial<Record<`${GridTier}`, number>>;
export type PriceGrid = Partial<Record<HotelCategory, PriceGridRow>>;

/** The categories a grid offers, in order: those with a 1-traveller price. Empty for no grid. */
export function gridCategories(grid: PriceGrid | null | undefined): HotelCategory[] {
  return HOTEL_CATEGORIES.filter((category) => typeof grid?.[category]?.['1'] === 'number');
}

/** The category a grid package is shown in until someone picks one: basic/3-star when offered (decided 2026-09-16), else the first. */
export function defaultHotelCategory(grid: PriceGrid | null | undefined): HotelCategory | null {
  const offered = gridCategories(grid);
  return offered.includes('3') ? '3' : (offered[0] ?? null);
}

/**
 * The per-person base price a package card, chip or list shows — triple sharing (decided 2026-09-28): from the grid in
 * `category` (an offered one, else the default) or, for a package without a grid, the list price after the group slab.
 * A fixed-departure group tour: its fixed price.
 */
export function packagePerPerson(pkg: { listPrice: number; priceGrid?: PriceGrid | null; fixedPrice?: boolean }, pax: number, slabs: readonly Slab[], category?: HotelCategory | null): number {
  if (pkg.fixedPrice) return Math.round(pkg.listPrice);
  const offered = gridCategories(pkg.priceGrid);
  if (offered.length === 0) return perPersonRate(pkg.listPrice, pax, slabs);
  const chosen = category && offered.includes(category) ? category : (defaultHotelCategory(pkg.priceGrid) as HotelCategory);
  return gridRate(pkg.priceGrid as PriceGrid, chosen, pax).perPerson;
}

/** Per-person price for `pax` travellers in a category: the highest tier at or below `pax` that has a price. */
export function gridRate(grid: PriceGrid, category: HotelCategory, pax: number): { tier: GridTier; perPerson: number } {
  assertTravellers(pax);
  const row = grid[category];
  if (!row || typeof row['1'] !== 'number') throw new RangeError(`@bhabaghure/pricing: the grid has no ${category}-star prices`);
  let match: { tier: GridTier; perPerson: number } | undefined;
  for (const tier of GRID_TIERS) {
    const price = row[`${tier}`];
    if (tier <= pax && typeof price === 'number') {
      assertAmount(price);
      match = { tier, perPerson: Math.round(price) };
    }
  }
  return match!;
}

export const DEFAULT_SLABS: readonly Slab[] = [
  { minPax: 1, discountPercent: 0 },
  { minPax: 3, discountPercent: 3 },
  { minPax: 4, discountPercent: 6 },
  { minPax: 6, discountPercent: 9 },
  { minPax: 10, discountPercent: 12 },
];

/** The price a package is sold at before any slab: the sale price when there is one. */
export function listPrice(pkg: { regularPrice: number; salePrice: number | null }): number {
  return pkg.salePrice ?? pkg.regularPrice;
}

/** Whole-percent saving of sale over regular price, rounded down so it never overstates. */
export function savingPercent(pkg: { regularPrice: number; salePrice: number | null }): number {
  if (pkg.salePrice == null || pkg.salePrice >= pkg.regularPrice) return 0;
  return Math.floor(((pkg.regularPrice - pkg.salePrice) / pkg.regularPrice) * 100);
}

export function clampTravellers(n: number, max: number): number {
  if (!Number.isFinite(n)) return 1;
  return Math.min(max, Math.max(1, Math.trunc(n)));
}

/** The tier that applies to `pax`: the highest minimum at or below it. */
export function slabFor(pax: number, slabs: readonly Slab[] = DEFAULT_SLABS): Slab {
  assertTravellers(pax);
  const sorted = [...slabs].sort((a, b) => a.minPax - b.minPax);
  let match: Slab | undefined;
  for (const slab of sorted) if (slab.minPax <= pax) match = slab;
  if (!match) throw new RangeError(`@bhabaghure/pricing: no slab covers ${pax} travellers`);
  return match;
}

/** Per-person price for a group of `pax` travellers. */
export function perPersonRate(list: number, pax: number, slabs: readonly Slab[] = DEFAULT_SLABS): number {
  assertAmount(list);
  const { discountPercent } = slabFor(pax, slabs);
  return Math.round((list * (100 - discountPercent)) / 100);
}

export interface QuoteInput {
  listPrice: number;
  pax: number;
  room: RoomType;
  /** Only the add-ons the traveller selected. */
  addons: readonly Addon[];
  config: PricingConfig;
  /**
   * Whole-taka discount: a staff member's on an invoice, plus a coupon's (docs/coupons.md). The website passes only a
   * coupon's discount as the API worked it out, and the API checks the total again.
   */
  discount?: number;
  /** VAT / service-charge rate chosen on an invoice; defaults to the configured service charge. */
  chargePercent?: number;
  /** The package's hotel-category price grid. When it offers any category, it replaces `listPrice` and the group slabs. */
  grid?: PriceGrid | null;
  /** Required with a grid: one of the categories it offers. */
  hotelCategory?: HotelCategory | null;
  /**
   * The package's own room charges (docs/room-rates.md). Absent — a booking made before 2026-09-28 — the old rule: a
   * single room adds the site-wide supplement and twin costs the same as triple.
   */
  rooms?: RoomRates | null;
  /**
   * A fixed-departure group tour (docs/fixed-departure-group-tours.md): `listPrice` per person whatever the group
   * size — no group-size discount and no hotel grid.
   */
  fixedPrice?: boolean;
}

/**
 * A package's room charges (decided 2026-09-28): triple sharing is the base price; a twin room adds
 * `twinSupplementPercent` and a single room `singleSupplementPercent` of it, per person.
 */
export interface RoomRates {
  singleSupplementPercent: number;
  twinSupplementPercent: number;
}

/** The room charge on top of the base is its own line: a single room's or a twin room's. */
export type LineKind = 'package' | 'single_supplement' | 'twin_supplement' | 'addon';

/** One priced line. Invoices print these; the booking stores them. */
export interface QuoteLine {
  kind: LineKind;
  /** Add-on code for 'addon' lines. */
  code: string | null;
  quantity: number;
  unitPrice: number;
  amount: number;
}

export interface Totals {
  subtotal: number;
  discount: number;
  /** Subtotal after discount: the amount the VAT / service charge applies to. */
  taxable: number;
  chargePercent: number;
  charge: number;
  total: number;
}

export interface Quote {
  pax: number;
  /** With a grid: the tier that priced it, and no discount. */
  slab: Slab;
  /** The hotel category a grid quote is for; null without a grid. */
  hotelCategory: HotelCategory | null;
  perPerson: number;
  /** Package line only (per-person rate × travellers). */
  subtotal: number;
  /** The room charge on top of the base: the single or twin supplement line's amount (bookings store it as single_supplement_amount). */
  singleSupplement: number;
  addons: { code: string; amount: number }[];
  discount: number;
  chargePercent: number;
  /** VAT / service charge on (all lines − discount). */
  serviceCharge: number;
  total: number;
  lines: QuoteLine[];
}

/**
 * Totals for any list of lines — bookings, invoices and quotations alike.
 * Decided 2026-09-13: the discount comes off first, and VAT / service charge applies to what is left.
 */
export function invoiceTotals({ lines, discount = 0, chargePercent }: { lines: readonly Pick<QuoteLine, 'quantity' | 'unitPrice'>[]; discount?: number; chargePercent: number }): Totals {
  assertAmount(discount);
  assertAmount(chargePercent);
  const subtotal = lines.reduce((sum, line) => {
    if (!Number.isInteger(line.quantity) || line.quantity < 0) throw new RangeError(`@bhabaghure/pricing: quantities must be whole numbers, got ${line.quantity}`);
    assertAmount(line.unitPrice);
    return sum + line.quantity * line.unitPrice;
  }, 0);
  const applied = Math.min(Math.round(discount), subtotal);
  const taxable = subtotal - applied;
  const charge = Math.round((taxable * chargePercent) / 100);
  return { subtotal, discount: applied, taxable, chargePercent, charge, total: taxable + charge };
}

export interface OnlinePayment {
  /** What goes to the booking. */
  amount: number;
  chargePercent: number;
  /** The online payment charge line; 0 when the company absorbs the gateway fee. */
  charge: number;
  /** Exactly what the gateway is asked to collect, and what the review and payment steps show. */
  total: number;
}

/** The amount to pay online for a booking balance: the balance plus the configured online payment charge. */
export function onlinePayment(amount: number, chargePercent: number): OnlinePayment {
  assertAmount(amount);
  assertAmount(chargePercent);
  const charge = Math.round((amount * chargePercent) / 100);
  return { amount, chargePercent, charge, total: amount + charge };
}

export type CouponDiscountType = 'percent' | 'fixed';

/** A coupon's terms as its discount needs them (docs/coupons.md §2.2). */
export interface CouponTerms {
  type: CouponDiscountType;
  /** A percentage (up to 100) or whole taka. */
  value: number;
  /** Percent coupons: the most one booking saves. */
  maxDiscount?: number | null;
  /** The booking amount the coupon needs. */
  minAmount?: number | null;
}

export interface CouponOutcome {
  /** False when the booking amount is below the coupon's minimum; the discount is then 0. */
  eligible: boolean;
  discount: number;
}

/**
 * A coupon's discount on the booking amount — every line before any discount and before the service charge, which then
 * applies to what is left (docs/coupons.md §2.2). Percent: the amount × rate in whole taka, capped at `maxDiscount`;
 * fixed: the value. Never more than the amount, so a total can't go below zero.
 */
export function couponDiscount(terms: CouponTerms, amount: number): CouponOutcome {
  assertAmount(amount);
  assertAmount(terms.value);
  if (terms.type === 'percent' && terms.value > 100) throw new RangeError(`@bhabaghure/pricing: a percentage coupon is at most 100%, got ${terms.value}`);
  if (terms.minAmount != null && amount < terms.minAmount) return { eligible: false, discount: 0 };
  let discount = terms.type === 'percent' ? Math.round((amount * terms.value) / 100) : Math.round(terms.value);
  if (terms.type === 'percent' && terms.maxDiscount != null) discount = Math.min(discount, Math.round(terms.maxDiscount));
  return { eligible: true, discount: Math.min(discount, amount) };
}

export type PaymentStatus = 'unpaid' | 'partial' | 'paid';

/** Derived from the amounts, never chosen: the PAID / PARTIAL / UNPAID pill. */
export function paymentStatus(total: number, paid: number): PaymentStatus {
  if (paid >= total) return 'paid';
  return paid <= 0 ? 'unpaid' : 'partial';
}

export function quoteBooking({ listPrice: list, pax, room, addons, config, discount = 0, chargePercent, grid, hotelCategory, rooms, fixedPrice = false }: QuoteInput): Quote {
  assertTravellers(pax);
  if (pax > config.maxTravellers) {
    throw new RangeError(`@bhabaghure/pricing: at most ${config.maxTravellers} travellers per booking`);
  }
  const offered = fixedPrice ? [] : gridCategories(grid);
  let slab: Slab;
  let perPerson: number;
  let category: HotelCategory | null = null;
  if (fixedPrice) {
    assertAmount(list);
    slab = { minPax: 1, discountPercent: 0 };
    perPerson = Math.round(list);
  } else if (offered.length > 0) {
    if (!hotelCategory || !offered.includes(hotelCategory)) {
      throw new RangeError(`@bhabaghure/pricing: choose one of the hotel categories ${offered.join(', ')}`);
    }
    const rate = gridRate(grid!, hotelCategory, pax);
    slab = { minPax: rate.tier, discountPercent: 0 };
    perPerson = rate.perPerson;
    category = hotelCategory;
  } else {
    slab = slabFor(pax, config.slabs);
    perPerson = perPersonRate(list, pax, config.slabs);
  }
  const lines: QuoteLine[] = [{ kind: 'package', code: null, quantity: pax, unitPrice: perPerson, amount: perPerson * pax }];
  // A grid's 1-traveller price already includes a single room (decided 2026-09-16); larger groups pay the supplement.
  const perPersonSupplement = room === 'single' && category !== null && pax === 1 ? 0 : roomSupplement(perPerson, room, rooms, config);
  if (perPersonSupplement > 0) {
    lines.push({ kind: room === 'single' ? 'single_supplement' : 'twin_supplement', code: null, quantity: pax, unitPrice: perPersonSupplement, amount: perPersonSupplement * pax });
  }
  for (const addon of addons) {
    assertAmount(addon.price);
    const quantity = addon.unit === 'per_booking' ? 1 : pax;
    lines.push({ kind: 'addon', code: addon.code, quantity, unitPrice: addon.price, amount: addon.price * quantity });
  }
  const totals = invoiceTotals({ lines, discount, chargePercent: chargePercent ?? config.serviceChargePercent });

  return {
    pax,
    slab,
    hotelCategory: category,
    perPerson,
    subtotal: lines[0].amount,
    singleSupplement: lines.find((line) => line.kind === 'single_supplement' || line.kind === 'twin_supplement')?.amount ?? 0,
    addons: lines.filter((line) => line.kind === 'addon').map((line) => ({ code: line.code as string, amount: line.amount })),
    discount: totals.discount,
    chargePercent: totals.chargePercent,
    serviceCharge: totals.charge,
    total: totals.total,
    lines,
  };
}

/**
 * A room's charge per person on top of the base (triple sharing): the package's twin or single percentage of it, in
 * whole taka. Without the package's rates (bookings made before 2026-09-28): the site-wide single supplement, twin free.
 */
export function roomSupplement(base: number, room: RoomType, rooms: RoomRates | null | undefined, config: Pick<PricingConfig, 'singleRoomSupplementPercent'>): number {
  if (room === 'triple') return 0;
  if (!rooms) return room === 'single' ? Math.round((base * config.singleRoomSupplementPercent) / 100) : 0;
  assertPercent(rooms.singleSupplementPercent);
  assertPercent(rooms.twinSupplementPercent);
  return Math.round((base * (room === 'single' ? rooms.singleSupplementPercent : rooms.twinSupplementPercent)) / 100);
}

/** What each room costs one traveller for a base price — the booking form's, package page's and brochure's room prices. */
export function roomPrices(base: number, rooms: RoomRates | null | undefined, config: Pick<PricingConfig, 'singleRoomSupplementPercent'>): Record<RoomType, number> {
  const price = (room: RoomType) => base + roomSupplement(base, room, rooms, config);
  return { twin: price('twin'), single: price('single'), triple: price('triple') };
}

function assertTravellers(pax: number): void {
  if (!Number.isInteger(pax) || pax < 1) {
    throw new RangeError(`@bhabaghure/pricing: travellers must be a whole number from 1, got ${pax}`);
  }
}

function assertPercent(percent: number): void {
  if (!Number.isFinite(percent) || percent < 0 || percent > 100) {
    throw new RangeError(`@bhabaghure/pricing: a percentage must be from 0 to 100, got ${percent}`);
  }
}

function assertAmount(amount: number): void {
  if (!Number.isFinite(amount) || amount < 0) {
    throw new RangeError(`@bhabaghure/pricing: amounts must be finite and non-negative, got ${amount}`);
  }
}
