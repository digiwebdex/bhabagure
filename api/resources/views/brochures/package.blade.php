<!doctype html>
{{-- Package brochure (docs/phase-8-visa-quotes-pricing-downloads.md §4.E): the price table (by hotel category or by group
     size), the itinerary and what is included. A4, on the shared letterhead. Data: App\Services\Brochures\BrochurePdf. --}}
@php
    $en = $locale === 'en';
    $l = fn (string $bn, string $en_) => $en ? $en_ : $bn;
    $pick = fn (?string $bn, ?string $en_) => ($en ? ($en_ ?: $bn) : ($bn ?: $en_)) ?? '';
    $money = fn ($amount) => \App\Support\Numerals::bdt($amount, $locale);
    $number = fn ($value) => \App\Support\Numerals::number($value, $locale);
    $percent = fn ($value) => \App\Support\Numerals::percent((float) $value, $locale);
    $date = fn (string $iso) => \App\Support\Numerals::date($iso, $locale);
    $title = $pick($package->title_bn, $package->title_en);
    $days = (int) $package->duration_days;
    $nights = $package->duration_nights;
    $includes = $package->inclusions->where('kind', 'include')->values();
    $excludes = $package->inclusions->where('kind', 'exclude')->values();
    $sizeLabel = fn (int $size, bool $last) => $last ? $number($size).'+ '.$l('জন', $size === 1 ? 'traveller' : 'travellers') : $number($size).' '.$l('জন', $size === 1 ? 'traveller' : 'travellers');
@endphp
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title }}</title>
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
  .page { width: 210mm; padding: 8mm 11mm; display: flex; flex-direction: column; gap: 4.5mm; font-size: 9.8pt; line-height: 1.5; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; border-bottom: 0.7mm solid var(--blue); padding-bottom: 3mm; }
  .letterhead img { width: 58mm; height: auto; }
  .company { text-align: right; font-size: 8.5pt; color: var(--muted); line-height: 1.5; }
  .company strong { display: block; font-size: 13pt; color: var(--ink); }
  .eyebrow { font-size: 8.5pt; letter-spacing: .1em; text-transform: uppercase; color: var(--orange); font-weight: 700; }
  h1 { margin: 0.5mm 0 0; font-size: 18pt; line-height: 1.25; }
  h2 { margin: 0 0 1.5mm; font-size: 12pt; color: var(--blue); }
  .summary { color: var(--muted); margin: 0; }
  .facts { display: flex; flex-wrap: wrap; gap: 2mm; }
  .fact { background: var(--wash); border-radius: 2mm; padding: 1.2mm 3mm; font-size: 9pt; }
  .your { display: flex; justify-content: space-between; align-items: center; gap: 4mm; background: var(--tint); border-radius: 2.6mm; padding: 3mm 4mm; }
  .your strong { font-size: 16pt; color: var(--orange); }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 1.6mm 2mm; border-bottom: 0.25mm solid var(--line); text-align: right; }
  th:first-child, td:first-child { text-align: left; }
  th { font-size: 8.3pt; color: var(--muted); font-weight: 600; }
  td.chosen { background: var(--tint); font-weight: 700; color: var(--orange); }
  .muted { color: var(--muted); }
  .small { font-size: 8.5pt; }
  ol.days { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 2.2mm; }
  ol.days li { break-inside: avoid; border-left: 0.6mm solid var(--blue); padding-left: 3mm; }
  ol.days strong { display: block; }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
  /* A heading never ends a page without its list. */
  .two > div { break-inside: avoid; }
  h2 { break-after: avoid; }
  ul.list { margin: 0; padding-left: 4.5mm; }
  ul.list li { margin: 0.6mm 0; }
  section { break-inside: auto; }
  .foot { border-top: 0.25mm solid var(--line); padding-top: 2.5mm; font-size: 8.3pt; color: var(--muted); }
</style>
</head>
<body>
<div class="page">
  <header class="letterhead">
    @if ($logo)<img src="{{ $logo }}" alt="">@endif
    <div class="company"><strong>{{ $company['name'] }}</strong>{{ $company['address'] }}@if ($company['phones'])<br>{{ $company['phones'] }}@endif @if ($company['email'])<br>{{ $company['email'] }}@endif</div>
  </header>

  <div>
    <span class="eyebrow">{{ $package->destination ? $pick($package->destination->name_bn, $package->destination->name_en) : '' }} · {{ $nights !== null ? $number($days).' '.$l('দিন', 'days').' / '.$number($nights).' '.$l('রাত', 'nights') : $number($days).' '.$l('দিন', 'days') }}</span>
    <h1>{{ $title }}</h1>
  </div>
  @if ($pick($package->summary_bn, $package->summary_en))<p class="summary">{{ $pick($package->summary_bn, $package->summary_en) }}</p>@endif
  <div class="facts">
    <span class="fact">{{ $package->includes_airfare === true ? $l('✈ এয়ার টিকেট সহ', '✈ Air ticket included') : ($package->includes_airfare === false ? $l('শুধু ল্যান্ড প্যাকেজ', 'Land package only') : $l('টিকেট: জিজ্ঞাসা করুন', 'Air ticket: ask us')) }}</span>
    <span class="fact num">{{ $package->code }}</span>
  </div>

  <div class="your">
    <span>
      <span class="eyebrow">{{ $l('আপনার বেছে নেওয়া', 'Your choice') }}</span><br>
      @if ($grid){{ \App\Enums\HotelCategory::from($category)->label($locale) }} {{ $l('হোটেল', 'hotel') }} · @endif{{ $number($pax) }} {{ $l('জন', $pax === 1 ? 'traveller' : 'travellers') }}
    </span>
    <span style="text-align:right"><strong class="num">{{ $money($yourPrice) }}</strong><br><span class="small muted">{{ $l('জনপ্রতি', 'per person') }} · {{ $l('মোট', 'total') }} <span class="num">{{ $money($yourPrice * $pax) }}</span></span></span>
  </div>

  <section>
    <h2>{{ $l('মূল্য (জনপ্রতি)', 'Price per person') }}</h2>
    <table>
      <thead>
        <tr>
          <th>{{ $grid ? $l('হোটেলের ধরন', 'Hotel category') : $l('দলের আকার', 'Group size') }}</th>
          @foreach ($rows[0]['cells'] as $i => $cell)<th class="num">{{ $sizeLabel($cell['size'], $i === count($rows[0]['cells']) - 1) }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $row)
          <tr>
            <td>{{ $row['label'] ?? $l('জনপ্রতি মূল্য', 'Per person') }}</td>
            @foreach ($row['cells'] as $i => $cell)
              @php $chosen = $row['category'] === $category && ($i === count($row['cells']) - 1 ? $pax >= $cell['size'] : ($pax >= $cell['size'] && $pax < $row['cells'][$i + 1]['size'])); @endphp
              <td class="num{{ $chosen ? ' chosen' : '' }}">{{ $money($cell['price']) }}</td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
    <p class="small muted" style="margin:1.5mm 0 0">
      @if ($grid)
        {{ $l('দুই আকারের মাঝের দল ছোট আকারের মূল্য দেয়। একজনের মূল্যে সিঙ্গেল রুম ধরা আছে; দুই বা বেশি জনের সিঙ্গেল রুমে +', 'A group between two sizes pays the smaller size’s price. The price for one includes a single room; for two or more a single room adds ') }}{{ $percent($config->singleRoomSupplementPercent) }}.
      @else
        {{ $l('একজন সিঙ্গেল রুম নিলে +', 'A single room adds ') }}{{ $percent($config->singleRoomSupplementPercent) }}.
      @endif
      {{ $l('সার্ভিস চার্জ ও ভ্যাট', 'Service charge and VAT') }} {{ $percent($config->serviceChargePercent) }}. {{ $l('মূল্য', 'Prices as of') }} {{ $date($asOf) }}{{ $l(' তারিখ অনুযায়ী; বুকিংয়ের সময়ের মূল্যই চূড়ান্ত।', '; the price at booking is final.') }}
    </p>
  </section>

  @if ($package->itineraryDays->isNotEmpty())
    <section>
      <h2>{{ $l('ভ্রমণসূচি', 'Itinerary') }}</h2>
      <ol class="days">
        @foreach ($package->itineraryDays->sortBy('day_number') as $day)
          <li><strong>{{ $l('দিন', 'Day') }} <span class="num">{{ $number($day->day_number) }}</span>@if ($pick($day->title_bn, $day->title_en)) · {{ $pick($day->title_bn, $day->title_en) }}@endif</strong>{{ $pick($day->body_bn, $day->body_en) }}</li>
        @endforeach
      </ol>
    </section>
  @endif

  <section class="two">
    <div>
      <h2>{{ $l('যা থাকছে', 'Included') }}</h2>
      <ul class="list">@foreach ($includes as $item)<li>{{ $pick($item->text_bn, $item->text_en) }}</li>@endforeach</ul>
    </div>
    @if ($excludes->isNotEmpty())
      <div>
        <h2>{{ $l('যা থাকছে না', 'Not included') }}</h2>
        <ul class="list">@foreach ($excludes as $item)<li>{{ $pick($item->text_bn, $item->text_en) }}</li>@endforeach</ul>
      </div>
    @endif
  </section>

  <p class="foot">
    {{ $l('বুকিং বা প্রশ্নের জন্য', 'To book or ask') }}: @if ($company['phones']){{ $company['phones'] }}@endif @if ($company['email']) · {{ $company['email'] }}@endif @if ($company['website']) · {{ $company['website'] }}@endif
    @if ($company['civilAviationNo'])<br>{{ $l('সিভিল এভিয়েশন নিবন্ধন নম্বর', 'Civil Aviation registration no.') }} {{ $company['civilAviationNo'] }}@endif
  </p>
</div>
</body>
</html>
