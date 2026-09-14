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
  /** Whole-taka discount a staff member applies on an invoice. The website never sends one. */
  discount?: number;
  /** VAT / service-charge rate chosen on an invoice; defaults to the configured service charge. */
  chargePercent?: number;
}

export type LineKind = 'package' | 'single_supplement' | 'addon';

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
  slab: Slab;
  perPerson: number;
  /** Package line only (per-person rate × travellers). */
  subtotal: number;
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

export type PaymentStatus = 'unpaid' | 'partial' | 'paid';

/** Derived from the amounts, never chosen: the PAID / PARTIAL / UNPAID pill. */
export function paymentStatus(total: number, paid: number): PaymentStatus {
  if (paid >= total) return 'paid';
  return paid <= 0 ? 'unpaid' : 'partial';
}

export function quoteBooking({ listPrice: list, pax, room, addons, config, discount = 0, chargePercent }: QuoteInput): Quote {
  assertTravellers(pax);
  if (pax > config.maxTravellers) {
    throw new RangeError(`@bhabaghure/pricing: at most ${config.maxTravellers} travellers per booking`);
  }
  const slab = slabFor(pax, config.slabs);
  const perPerson = perPersonRate(list, pax, config.slabs);
  const lines: QuoteLine[] = [{ kind: 'package', code: null, quantity: pax, unitPrice: perPerson, amount: perPerson * pax }];
  if (room === 'single') {
    const perPersonSupplement = Math.round((perPerson * config.singleRoomSupplementPercent) / 100);
    lines.push({ kind: 'single_supplement', code: null, quantity: pax, unitPrice: perPersonSupplement, amount: perPersonSupplement * pax });
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
    perPerson,
    subtotal: lines[0].amount,
    singleSupplement: lines.find((line) => line.kind === 'single_supplement')?.amount ?? 0,
    addons: lines.filter((line) => line.kind === 'addon').map((line) => ({ code: line.code as string, amount: line.amount })),
    discount: totals.discount,
    chargePercent: totals.chargePercent,
    serviceCharge: totals.charge,
    total: totals.total,
    lines,
  };
}

function assertTravellers(pax: number): void {
  if (!Number.isInteger(pax) || pax < 1) {
    throw new RangeError(`@bhabaghure/pricing: travellers must be a whole number from 1, got ${pax}`);
  }
}

function assertAmount(amount: number): void {
  if (!Number.isFinite(amount) || amount < 0) {
    throw new RangeError(`@bhabaghure/pricing: amounts must be finite and non-negative, got ${amount}`);
  }
}
