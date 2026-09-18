<!doctype html>
{{-- Visa requirements (docs/phase-8-visa-quotes-pricing-downloads.md §4.E): price, processing time, stay, the numbered
     requirements in their groups (VisaService::groups) and notes, from Admin → Visa services. A4, on the shared letterhead.
     Data: App\Services\Brochures\BrochurePdf. --}}
@php
    $en = $locale === 'en';
    $l = fn (string $bn, string $en_) => $en ? $en_ : $bn;
    $pick = fn (?string $bn, ?string $en_) => ($en ? ($en_ ?: $bn) : ($bn ?: $en_)) ?? '';
    $money = fn ($amount) => \App\Support\Numerals::bdt($amount, $locale);
    $date = fn (string $iso) => \App\Support\Numerals::date($iso, $locale);
    $number = fn ($value) => \App\Support\Numerals::number($value, $locale);
    $country = $pick($visa->country_bn, $visa->country_en);
    $type = $pick($visa->visa_type_bn, $visa->visa_type_en);
    $notes = $pick($visa->notes_bn, $visa->notes_en);
@endphp
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $country }} {{ $type }}</title>
@if ($forPdf)
<!--bh:fonts-->
@else
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&display=swap" rel="stylesheet">
@endif
<style>
  @page { size: A4; margin: 10mm 0; }
  @page :first { margin-top: 0; }
  :root { --ink: #0F1E3A; --muted: #4A5A78; --blue: #0B5ED7; --orange: #F0500F; --line: #E1E7F2; --wash: #F4F8FF; --tint: #FFF2EA; }
  * { box-sizing: border-box; }
  html, body { margin: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .num { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .page { width: 210mm; padding: 8mm 11mm; display: flex; flex-direction: column; gap: 3.5mm; font-size: 10pt; line-height: 1.55; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; border-bottom: 0.7mm solid var(--blue); padding-bottom: 3mm; }
  .letterhead img { width: 58mm; height: auto; }
  .company { text-align: right; font-size: 8.5pt; color: var(--muted); line-height: 1.5; }
  .company strong { display: block; font-size: 13pt; color: var(--ink); }
  .eyebrow { font-size: 8.5pt; letter-spacing: .1em; text-transform: uppercase; color: var(--orange); font-weight: 700; }
  h1 { margin: 0.5mm 0 0; font-size: 19pt; line-height: 1.25; }
  h2 { margin: 0 0 1.5mm; font-size: 12pt; color: var(--blue); }
  .facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; }
  .fact { background: var(--wash); border-radius: 2.4mm; padding: 2.4mm 3.2mm; display: flex; flex-direction: column; }
  .fact span { font-size: 8pt; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }
  .fact strong { font-size: 11pt; }
  /* Numbers in a fixed left-aligned column: Chrome right-aligns list markers, which staggers Bengali digits of different widths. */
  ol.req { margin: 0; padding: 0; list-style: none; counter-reset: req; }
  /* Spaced to fit the whole grouped checklist, its notes and the footer on one A4 page. */
  ol.req li { margin: 0.5mm 0; line-height: 1.4; break-inside: avoid; counter-increment: req; display: grid; grid-template-columns: 7mm 1fr; }
  ol.req li::before { content: counter(req) "."; color: var(--muted); }
  ol.req.bn li::before { content: counter(req, bengali) "."; }
  /* A group heading ("For business person") keeps with the first lines under it; each group numbers from one. */
  h3.req-group { margin: 2.2mm 0 0.3mm; font-size: 10.5pt; break-after: avoid; }
  .notes { background: var(--tint); border-radius: 2.6mm; padding: 3mm 4mm; white-space: pre-line; }
  .muted { color: var(--muted); }
  .foot { margin: 0; border-top: 0.25mm solid var(--line); padding-top: 2.5mm; font-size: 8.3pt; color: var(--muted); }
</style>
</head>
<body>
<div class="page">
  <header class="letterhead">
    @if ($logo)<img src="{{ $logo }}" alt="">@endif
    <div class="company"><strong>{{ $company['name'] }}</strong>{{ $company['address'] }}@if ($company['phones'])<br>{{ $company['phones'] }}@endif @if ($company['email'])<br>{{ $company['email'] }}@endif</div>
  </header>

  <div>
    <span class="eyebrow">{{ $l('ভিসা সেবা', 'Visa services') }}@if ($visa->country_code) · <span class="num">{{ $visa->country_code }}</span>@endif</span>
    <h1>{{ $country }} {{ $type }}</h1>
  </div>

  <div class="facts">
    <div class="fact"><span>{{ $l('মূল্য', 'Price') }}</span><strong class="num">{{ $visa->price === null ? $l('জানতে যোগাযোগ করুন', 'On request') : $money($visa->price).' '.$l('জনপ্রতি', 'per person') }}</strong></div>
    {{-- The processing time is optional (2026-09-19): without one, the reader is asked to get in touch. --}}
    <div class="fact"><span>{{ $l('প্রসেসিং সময়', 'Processing time') }}</span><strong>{{ $pick($visa->processing_bn, $visa->processing_en) ?: $l('জানতে যোগাযোগ করুন', 'Ask us') }}</strong></div>
    @if ($pick($visa->stay_bn, $visa->stay_en))<div class="fact"><span>{{ $l('থাকার মেয়াদ', 'Stay') }}</span><strong>{{ $pick($visa->stay_bn, $visa->stay_en) }}</strong></div>@endif
  </div>

  <section>
    <h2>{{ $l('প্রয়োজনীয় কাগজপত্র', 'Requirements') }}</h2>
    @foreach ($groups as $group)
      @if ($group['heading'])<h3 class="req-group">{{ $group['heading'] }}</h3>@endif
      <ol class="req{{ $en ? '' : ' bn' }}">
        @foreach ($group['items'] as $requirement)<li>{{ $requirement }}</li>@endforeach
      </ol>
    @endforeach
  </section>

  @if ($notes)
    <section>
      <h2>{{ $l('জেনে রাখুন', 'Good to know') }}</h2>
      <div class="notes">{{ $notes }}</div>
    </section>
  @endif

  <p class="muted" style="margin:0">{{ $l('এই তথ্য', 'This information is as of') }} {{ $date($asOf) }}{{ $l(' তারিখ অনুযায়ী। দূতাবাসের নিয়ম বদলাতে পারে; আবেদনের আগে আমাদের সঙ্গে মিলিয়ে নিন।', '. Embassy rules can change; check with us before you apply.') }}</p>

  <p class="foot">
    {{ $l('আবেদন বা প্রশ্নের জন্য', 'To apply or ask') }}: @if ($company['phones']){{ $company['phones'] }}@endif @if ($company['email']) · {{ $company['email'] }}@endif @if ($company['website']) · {{ $company['website'] }}@endif
    @if ($company['civilAviationNo'])<br>{{ $l('সিভিল এভিয়েশন নিবন্ধন নম্বর', 'Civil Aviation registration no.') }} {{ $company['civilAviationNo'] }}@endif
  </p>
</div>
</body>
</html>
