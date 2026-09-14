/**
 * DRAFT legal pages — terms, privacy and refund policy (docs/phase-3-booking.md §7).
 *
 * Written from what the site and invoice already say (21-day cancellation, non-refundable air-ticket and hotel
 * portions, visa processing fee, six-month passport validity, SSLCommerz card handling, passport scan storage).
 * They are NOT final and NOT legal advice: every page shows a "draft — pending review by the company's lawyer" banner
 * and is noindex while `status` is 'draft'. Points the business hasn't decided are marked [CLIENT TO CONFIRM].
 * Only the client's lawyer should change `status` to 'approved', after replacing those markers.
 */

import type { AppLocale } from '@/i18n/routing';

export type LegalSlug = 'terms' | 'privacy' | 'refund-policy';
export type LegalSection = { heading: string; paragraphs: string[] };
export type LegalDocument = {
  title: string;
  updated: string;
  intro: string;
  sections: LegalSection[];
};

export const LEGAL_STATUS: 'draft' | 'approved' = 'draft';
export const LEGAL_VERSION = 'draft-2026-09';

const CONFIRM = '[CLIENT TO CONFIRM]';
const CONFIRM_BN = '[ক্লায়েন্ট নিশ্চিত করবেন]';

const en: Record<LegalSlug, LegalDocument> = {
  terms: {
    title: 'Booking terms and conditions',
    updated: 'Draft of September 2026',
    intro:
      'These terms apply to tour packages booked with Bhabaghure Holidays Aviation ("we", "us") on this website, by phone, on WhatsApp or at our office. By confirming a booking you agree to them.',
    sections: [
      {
        heading: '1. Who we are',
        paragraphs: [
          `Bhabaghure Holidays Aviation, AMENA VILLA, 213/5, Lift-08, Flat-8C, 60 Feet Road, Agargaon, Dhaka 1207. Civil Aviation licence no. 0017318. Trade licence and registration details: ${CONFIRM}.`,
        ],
      },
      {
        heading: '2. Your booking',
        paragraphs: [
          'A booking made online starts as an inquiry. It is confirmed when we receive payment and send you an invoice. Until then prices, seats and availability are not guaranteed.',
          'The price is shown before you pay and is the price you are charged. If a price changes while you are booking, we show you the new total and ask again before charging anything.',
          `Seats on a group departure are held for 45 minutes while you pay. Minimum group size and what happens if a departure does not reach it: ${CONFIRM}.`,
        ],
      },
      {
        heading: '3. Prices and payment',
        paragraphs: [
          'Package prices are per person in Bangladeshi taka and depend on the number of travellers. A single room adds a supplement. A service charge and VAT are added on the amount after any discount; the rate is shown on your quote and invoice.',
          'Online payments are processed by SSLCommerz. SSLCommerz may add a convenience fee on its payment page; that fee is shown there before you pay and is not part of the package price.',
          `Payment schedule for bookings paid in instalments or with an advance at our office: ${CONFIRM}.`,
        ],
      },
      {
        heading: '4. Travel documents',
        paragraphs: [
          'Your passport must be valid for at least six months after the travel date. You are responsible for holding a valid passport and any visa you need; we help with the process.',
          'Visa decisions are made by the embassy concerned. A visa processing fee is not refundable if the visa is refused.',
          'Names on the booking must match the passport exactly. Correction charges after tickets are issued: ' + CONFIRM + '.',
        ],
      },
      {
        heading: '5. Cancellations and changes',
        paragraphs: [
          'Cancellations and refunds are covered by our refund policy, which forms part of these terms.',
          `Changes you ask for after confirmation (dates, travellers, rooms) and their charges: ${CONFIRM}.`,
        ],
      },
      {
        heading: '6. Changes by us',
        paragraphs: [`If we must change or cancel a departure (for example weather, flight or government action), your options and our liability: ${CONFIRM}.`],
      },
      {
        heading: '7. Responsibility',
        paragraphs: [
          `The extent of our liability for services provided by airlines, hotels and transport operators: ${CONFIRM}. Travel insurance is recommended and offered as an add-on.`,
        ],
      },
      {
        heading: '8. Law and disputes',
        paragraphs: [`These terms are governed by the laws of Bangladesh. Courts and venue for disputes: ${CONFIRM}.`],
      },
    ],
  },
  'refund-policy': {
    title: 'Cancellation and refund policy',
    updated: 'Draft of September 2026',
    intro: 'This policy explains what happens if you cancel a confirmed booking.',
    sections: [
      {
        heading: '1. Cancelling more than 21 days before departure',
        paragraphs: [
          'You are refunded the amount you paid, except the non-refundable portion of air tickets and hotels, and any visa processing fee already paid to an embassy.',
          `How the non-refundable portion is calculated and shown to you: ${CONFIRM}.`,
        ],
      },
      {
        heading: '2. Cancelling 21 days or less before departure',
        paragraphs: [`Refund, if any, for cancellations within 21 days of departure: ${CONFIRM}.`],
      },
      {
        heading: '3. No-show',
        paragraphs: [`If you do not travel without cancelling: ${CONFIRM}.`],
      },
      {
        heading: '4. How refunds are paid',
        paragraphs: [
          'Refunds go back through the method you paid with where possible: card and online payments through SSLCommerz, bKash or Nagad to the same account, cash and bank payments by bank transfer.',
          `Time to process a refund: ${CONFIRM}. The SSLCommerz convenience fee is not refundable ${CONFIRM}.`,
        ],
      },
      {
        heading: '5. How to cancel',
        paragraphs: ['Tell us in writing — WhatsApp or email — with your booking reference. The date we receive your message is the cancellation date.'],
      },
    ],
  },
  privacy: {
    title: 'Privacy policy',
    updated: 'Draft of September 2026',
    intro: 'This policy explains what personal information we collect when you book or contact us, why, and how we protect it.',
    sections: [
      {
        heading: '1. What we collect',
        paragraphs: [
          'Contact details (name, mobile number, email), booking details, and for each traveller: name, date of birth, nationality, passport number and expiry — the details airlines, hotels and embassies require.',
          'If you upload a passport scan, the image itself.',
          'We never receive or store your card details: card payments are entered on the SSLCommerz page.',
        ],
      },
      {
        heading: '2. Why we use it',
        paragraphs: ['To arrange your trip (tickets, hotels, visas), send your invoice and travel updates, and meet legal and accounting obligations.'],
      },
      {
        heading: '3. Passport scans',
        paragraphs: [
          'Scans are stored encrypted and are never public. The machine-readable lines are read automatically to fill in your details; you check the result before booking.',
          `Automated reading uses a cloud text-recognition service ${CONFIRM} (provider and region). A scan not used for a booking is deleted after 24 hours. Scans used for a booking are kept for ${CONFIRM} after travel.`,
        ],
      },
      {
        heading: '4. Who we share it with',
        paragraphs: [
          'Only with those needed for your trip — airlines, hotels, transport and tour partners, embassies — and with SSLCommerz to process payment. We do not sell personal information.',
        ],
      },
      {
        heading: '5. How long we keep it',
        paragraphs: [`Booking and invoice records are kept as accounting law requires ${CONFIRM}. Passport numbers are stored encrypted.`],
      },
      {
        heading: '6. Your choices',
        paragraphs: [
          `You can ask for a copy of your information or ask us to correct it. Requests to delete it: ${CONFIRM}. Newsletter emails carry an unsubscribe link.`,
        ],
      },
      {
        heading: '7. Contact',
        paragraphs: ['bhabaghureholidays@gmail.com · +880 1743 939300.'],
      },
    ],
  },
};

const bn: Record<LegalSlug, LegalDocument> = {
  terms: {
    title: 'বুকিংয়ের শর্তাবলী',
    updated: 'খসড়া, সেপ্টেম্বর ২০২৬',
    intro:
      'এই ওয়েবসাইট, ফোন, WhatsApp বা আমাদের অফিসে ভবঘুরে হলিডেজ এভিয়েশনের ("আমরা") সাথে বুক করা ট্যুর প্যাকেজের ক্ষেত্রে এই শর্তাবলী প্রযোজ্য। বুকিং নিশ্চিত করার মাধ্যমে আপনি এগুলোতে সম্মত হন।',
    sections: [
      {
        heading: '১. আমাদের পরিচয়',
        paragraphs: [
          `ভবঘুরে হলিডেজ এভিয়েশন, আমেনা ভিলা, ২১৩/৫, লিফট-০৮, ফ্ল্যাট-৮সি, ৬০ ফিট রোড, আগারগাঁও, ঢাকা ১২০৭। সিভিল এভিয়েশন লাইসেন্স নং ০০১৭৩১৮। ট্রেড লাইসেন্স ও নিবন্ধনের তথ্য: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '২. আপনার বুকিং',
        paragraphs: [
          'অনলাইনে করা বুকিং প্রথমে ইনকোয়ারি হিসেবে থাকে। পেমেন্ট পেয়ে ইনভয়েস পাঠানোর পর বুকিং নিশ্চিত হয়। তার আগে দাম, সিট ও প্রাপ্যতার নিশ্চয়তা নেই।',
          'পেমেন্টের আগে যে দাম দেখানো হয়, সেটিই নেওয়া হয়। বুকিংয়ের সময় দাম বদলালে নতুন মোট দেখিয়ে আবার অনুমতি নেওয়া হয়।',
          `গ্রুপ ডিপার্চারে পেমেন্টের সময় ৪৫ মিনিট সিট ধরে রাখা হয়। ন্যূনতম গ্রুপ সাইজ ও তা পূরণ না হলে কী হবে: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '৩. দাম ও পেমেন্ট',
        paragraphs: [
          'প্যাকেজের দাম জনপ্রতি টাকায় এবং যাত্রীর সংখ্যার ওপর নির্ভর করে। সিঙ্গেল রুমে সাপ্লিমেন্ট যোগ হয়। ডিসকাউন্টের পরের অঙ্কের ওপর সার্ভিস চার্জ ও ভ্যাট যোগ হয়; হার কোটেশন ও ইনভয়েসে দেখানো থাকে।',
          'অনলাইন পেমেন্ট SSLCommerz-এর মাধ্যমে হয়। SSLCommerz তাদের পেমেন্ট পেজে কনভিনিয়েন্স ফি যোগ করতে পারে; তা পেমেন্টের আগে সেখানে দেখানো হয় এবং প্যাকেজের দামের অংশ নয়।',
          `অফিসে কিস্তি বা অগ্রিমে পেমেন্টের সময়সূচি: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '৪. ভ্রমণের কাগজপত্র',
        paragraphs: [
          'ভ্রমণের তারিখ থেকে পাসপোর্টের মেয়াদ কমপক্ষে ৬ মাস থাকতে হবে। বৈধ পাসপোর্ট ও প্রয়োজনীয় ভিসা রাখার দায়িত্ব আপনার; আমরা প্রক্রিয়ায় সহায়তা করি।',
          'ভিসার সিদ্ধান্ত সংশ্লিষ্ট দূতাবাসের। ভিসা প্রত্যাখ্যাত হলে প্রসেসিং ফি ফেরতযোগ্য নয়।',
          `বুকিংয়ের নাম পাসপোর্টের সাথে হুবহু মিলতে হবে। টিকেট ইস্যুর পর সংশোধনের চার্জ: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '৫. বাতিল ও পরিবর্তন',
        paragraphs: [
          'বাতিল ও রিফান্ড আমাদের রিফান্ড নীতিমালা অনুযায়ী হয়, যা এই শর্তাবলীর অংশ।',
          `নিশ্চিতকরণের পর আপনার চাওয়া পরিবর্তন (তারিখ, যাত্রী, রুম) ও তার চার্জ: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '৬. আমাদের দিক থেকে পরিবর্তন',
        paragraphs: [`আবহাওয়া, ফ্লাইট বা সরকারি সিদ্ধান্তে ডিপার্চার বদলাতে বা বাতিল করতে হলে আপনার বিকল্প ও আমাদের দায়: ${CONFIRM_BN}।`],
      },
      {
        heading: '৭. দায়বদ্ধতা',
        paragraphs: [
          `এয়ারলাইন, হোটেল ও পরিবহন প্রতিষ্ঠানের সেবার ক্ষেত্রে আমাদের দায়ের পরিধি: ${CONFIRM_BN}। ট্রাভেল ইনস্যুরেন্স নেওয়ার পরামর্শ দেওয়া হয় এবং অ্যাড-অন হিসেবে পাওয়া যায়।`,
        ],
      },
      {
        heading: '৮. আইন ও বিরোধ',
        paragraphs: [`এই শর্তাবলী বাংলাদেশের আইন অনুযায়ী পরিচালিত। বিরোধ নিষ্পত্তির আদালত ও স্থান: ${CONFIRM_BN}।`],
      },
    ],
  },
  'refund-policy': {
    title: 'বাতিল ও রিফান্ড নীতিমালা',
    updated: 'খসড়া, সেপ্টেম্বর ২০২৬',
    intro: 'নিশ্চিত বুকিং বাতিল করলে কী হয়, এই নীতিমালায় তা বলা আছে।',
    sections: [
      {
        heading: '১. যাত্রার ২১ দিনের বেশি আগে বাতিল',
        paragraphs: [
          'এয়ার টিকেট ও হোটেলের নন-রিফান্ডেবল অংশ এবং দূতাবাসে জমা দেওয়া ভিসা প্রসেসিং ফি বাদে পরিশোধিত অর্থ ফেরত দেওয়া হয়।',
          `নন-রিফান্ডেবল অংশ কীভাবে হিসাব করে আপনাকে জানানো হবে: ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '২. যাত্রার ২১ দিন বা তার কম আগে বাতিল',
        paragraphs: [`যাত্রার ২১ দিনের মধ্যে বাতিলে রিফান্ড (যদি থাকে): ${CONFIRM_BN}।`],
      },
      {
        heading: '৩. যাত্রায় উপস্থিত না হলে',
        paragraphs: [`বাতিল না করে যাত্রা না করলে: ${CONFIRM_BN}।`],
      },
      {
        heading: '৪. রিফান্ড কীভাবে দেওয়া হয়',
        paragraphs: [
          'সম্ভব হলে যে পদ্ধতিতে পেমেন্ট করেছেন সেভাবেই ফেরত: কার্ড ও অনলাইন পেমেন্ট SSLCommerz-এর মাধ্যমে, বিকাশ বা নগদ একই অ্যাকাউন্টে, নগদ ও ব্যাংক পেমেন্ট ব্যাংক ট্রান্সফারে।',
          `রিফান্ড প্রক্রিয়ার সময়: ${CONFIRM_BN}। SSLCommerz কনভিনিয়েন্স ফি ফেরতযোগ্য নয় ${CONFIRM_BN}।`,
        ],
      },
      {
        heading: '৫. কীভাবে বাতিল করবেন',
        paragraphs: ['বুকিং রেফারেন্সসহ লিখিতভাবে — WhatsApp বা ইমেইলে — জানান। আমাদের কাছে বার্তা পৌঁছানোর তারিখই বাতিলের তারিখ।'],
      },
    ],
  },
  privacy: {
    title: 'গোপনীয়তা নীতি',
    updated: 'খসড়া, সেপ্টেম্বর ২০২৬',
    intro: 'বুকিং বা যোগাযোগের সময় আমরা কোন ব্যক্তিগত তথ্য নিই, কেন নিই এবং কীভাবে সুরক্ষিত রাখি — এই নীতিতে তা বলা আছে।',
    sections: [
      {
        heading: '১. যা সংগ্রহ করি',
        paragraphs: [
          'যোগাযোগের তথ্য (নাম, মোবাইল, ইমেইল), বুকিংয়ের তথ্য এবং প্রত্যেক যাত্রীর নাম, জন্মতারিখ, জাতীয়তা, পাসপোর্ট নম্বর ও মেয়াদ — যা এয়ারলাইন, হোটেল ও দূতাবাসের প্রয়োজন।',
          'পাসপোর্ট স্ক্যান আপলোড করলে সেই ছবি।',
          'আপনার কার্ডের তথ্য আমরা কখনো পাই না বা রাখি না: কার্ডের তথ্য SSLCommerz-এর পেজে দেওয়া হয়।',
        ],
      },
      {
        heading: '২. যে কারণে ব্যবহার করি',
        paragraphs: ['আপনার ভ্রমণের ব্যবস্থা (টিকেট, হোটেল, ভিসা), ইনভয়েস ও ভ্রমণের আপডেট পাঠানো এবং আইনি ও হিসাবসংক্রান্ত বাধ্যবাধকতা পূরণের জন্য।'],
      },
      {
        heading: '৩. পাসপোর্ট স্ক্যান',
        paragraphs: [
          'স্ক্যান এনক্রিপ্ট করে রাখা হয় এবং কখনো প্রকাশ্য নয়। মেশিন-রিডেবল লাইন স্বয়ংক্রিয়ভাবে পড়ে তথ্য পূরণ করা হয়; বুকিংয়ের আগে আপনি তা মিলিয়ে নেন।',
          `স্বয়ংক্রিয় পড়ার জন্য একটি ক্লাউড টেক্সট-রিকগনিশন সেবা ব্যবহার হয় ${CONFIRM_BN} (প্রতিষ্ঠান ও অঞ্চল)। বুকিংয়ে ব্যবহার না হওয়া স্ক্যান ২৪ ঘণ্টা পর মুছে ফেলা হয়। বুকিংয়ে ব্যবহৃত স্ক্যান ভ্রমণের পর ${CONFIRM_BN} পর্যন্ত রাখা হয়।`,
        ],
      },
      {
        heading: '৪. যাদের সাথে শেয়ার করি',
        paragraphs: [
          'শুধু আপনার ভ্রমণের জন্য যাদের দরকার — এয়ারলাইন, হোটেল, পরিবহন ও ট্যুর পার্টনার, দূতাবাস — এবং পেমেন্টের জন্য SSLCommerz। আমরা ব্যক্তিগত তথ্য বিক্রি করি না।',
        ],
      },
      {
        heading: '৫. কতদিন রাখি',
        paragraphs: [`হিসাবসংক্রান্ত আইন অনুযায়ী বুকিং ও ইনভয়েসের রেকর্ড রাখা হয় ${CONFIRM_BN}। পাসপোর্ট নম্বর এনক্রিপ্ট করে রাখা হয়।`],
      },
      {
        heading: '৬. আপনার অধিকার',
        paragraphs: [`আপনার তথ্যের কপি চাইতে বা সংশোধন করতে বলতে পারেন। মুছে ফেলার অনুরোধ: ${CONFIRM_BN}। নিউজলেটার ইমেইলে আনসাবস্ক্রাইবের লিংক থাকে।`],
      },
      {
        heading: '৭. যোগাযোগ',
        paragraphs: ['bhabaghureholidays@gmail.com · +৮৮০ ১৭৪৩ ৯৩৯৩০০।'],
      },
    ],
  },
};

export function legalDocument(slug: LegalSlug, locale: AppLocale): LegalDocument {
  return (locale === 'en' ? en : bn)[slug];
}
