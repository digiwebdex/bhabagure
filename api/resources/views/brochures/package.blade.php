<!doctype html>
{{-- Package brochure (docs/phase-8-visa-quotes-pricing-downloads.md §4.E, docs/group-tour-brochures.md): a long,
     photo-led page in the website's landing style — the tour's cover as a banner, the prices, the itinerary and what is
     included, between rows of photos from the tour and the Group Tour Gallery. A group tour shows room prices and
     departure dates; a customized trip its price table by hotel category or group size, the chosen one highlighted.
     Every word and number comes from the package; photos keep their own shape (App\Services\Brochures\BrochurePhotos).
     A4, on the shared letterhead. Data: App\Services\Brochures\BrochurePdf. --}}
@php
    $en = $locale === 'en';
    $l = fn (string $bn, string $en_) => $en ? $en_ : $bn;
    $pick = fn (?string $bn, ?string $en_) => ($en ? ($en_ ?: $bn) : ($bn ?: $en_)) ?? '';
    $money = fn ($amount) => \App\Support\Numerals::bdt($amount, $locale);
    $number = fn ($value) => \App\Support\Numerals::number($value, $locale);
    $percent = fn ($value) => \App\Support\Numerals::percent((float) $value, $locale);
    $date = fn (string $iso) => \App\Support\Numerals::date($iso, $locale);
    $title = $pick($package->title_bn, $package->title_en);
    $summary = $pick($package->summary_bn, $package->summary_en);
    $days = (int) $package->duration_days;
    $nights = $package->duration_nights;
    $duration = $nights !== null ? $number($days).' '.$l('দিন', 'days').' / '.$number($nights).' '.$l('রাত', 'nights') : $number($days).' '.$l('দিন', 'days');
    $destination = $package->destination ? $pick($package->destination->name_bn, $package->destination->name_en) : '';
    $includes = $package->inclusions->where('kind', 'include')->values();
    $excludes = $package->inclusions->where('kind', 'exclude')->values();
    $itinerary = $package->itineraryDays->sortBy('day_number')->values();
    // The second row of photos sits in the middle of the itinerary; with one day or none, after it.
    $bandAfter = $itinerary->count() >= 2 ? intdiv($itinerary->count() + 1, 2) : $itinerary->count();
    $travellers = fn (int $size) => $number($size).' '.$l('জন', $size === 1 ? 'traveller' : 'travellers');
    $sizeLabel = fn (int $size, bool $last) => $last ? $number($size).'+ '.$l('জন', 'travellers') : $travellers($size);
    $airfare = $package->includes_airfare === true ? $l('এয়ার টিকেট সহ', 'Air ticket included') : ($package->includes_airfare === false ? $l('শুধু ল্যান্ড প্যাকেজ', 'Land package only') : $l('টিকেট: জিজ্ঞাসা করুন', 'Air ticket: ask us'));
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
  :root { --ink: #0F1E3A; --muted: #4A5A78; --blue: #0B5ED7; --navy: #0A2A5E; --orange: #F0500F; --green: #138A4F; --red: #C2410C; --line: #E1E7F2; --wash: #F4F8FF; --tint: #FFF2EA; }
  * { box-sizing: border-box; }
  html, body { margin: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; font-size: 9.8pt; line-height: 1.5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { width: 210mm; }
  .num, .display { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .pad { padding: 0 11mm; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; padding-top: 8mm; padding-bottom: 3mm; border-bottom: 0.7mm solid var(--blue); margin: 0 11mm; }
  .letterhead img { width: 58mm; height: auto; }
  .company { text-align: right; font-size: 8.5pt; color: var(--muted); line-height: 1.5; }
  .company strong { display: block; font-size: 13pt; color: var(--ink); }
  .eyebrow { font-size: 8.5pt; letter-spacing: .1em; text-transform: uppercase; color: var(--orange); font-weight: 700; }
  h1 { margin: 1mm 0 0; font-size: 24pt; line-height: 1.18; font-weight: 800; }
  h2 { margin: 0 0 2.5mm; font-size: 14pt; line-height: 1.3; color: var(--navy); font-weight: 800; break-after: avoid; break-inside: avoid; }
  h2 .eyebrow { display: block; font-size: 8pt; margin-bottom: 0.5mm; }
  /* Spaced letters break Bengali conjuncts. */
  html[lang="bn"] .eyebrow, html[lang="bn"] .label, html[lang="bn"] .badge small { letter-spacing: 0; }
  section.whole { break-inside: avoid; }
  p { margin: 0; }
  .muted { color: var(--muted); }
  .small { font-size: 8.5pt; }
  section { margin-top: 7mm; }

  /* The banner: the tour's cover across the page, the title over its darkened foot. */
  .hero { position: relative; width: 210mm; margin-top: 4mm; overflow: hidden; background: var(--navy); }
  .hero img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .hero .shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(10, 22, 48, 0) 35%, rgba(10, 22, 48, .82) 100%); }
  .hero .words { position: absolute; left: 11mm; right: 11mm; bottom: 8mm; color: #fff; }
  .hero .eyebrow { color: #FFD9C7; }
  .hero h1 { color: #fff; }
  .titleblock { margin: 6mm 11mm 0; }
  .chips { display: flex; flex-wrap: wrap; gap: 2mm; margin-top: 3mm; }
  .chip { display: inline-block; border-radius: 10mm; padding: 1mm 3.5mm; font-size: 8.5pt; font-weight: 600; background: var(--wash); color: var(--navy); }
  .hero .chip { background: rgba(255, 255, 255, .92); }
  .hero .chip.accent, .chip.accent { background: var(--orange); color: #fff; }

  .facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; margin-top: 6mm; }
  .fact { background: var(--wash); border-radius: 3mm; padding: 3mm 3.5mm; }
  .fact .label { display: block; font-size: 7.8pt; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
  .fact .value { display: block; font-size: 11pt; font-weight: 700; line-height: 1.3; margin-top: 0.8mm; }
  .fact.price { background: var(--tint); }
  .fact.price .value { color: var(--orange); font-size: 13pt; }
  .summary { margin-top: 5mm; color: var(--muted); font-size: 10.5pt; }
  .your { display: flex; justify-content: space-between; align-items: center; gap: 4mm; border: 0.35mm solid #F7C4A8; border-radius: 3mm; padding: 3mm 4mm; margin-top: 5mm; }
  .your strong { font-size: 16pt; color: var(--orange); }

  .prices { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; break-inside: avoid; }
  .card { border: 0.3mm solid var(--line); border-radius: 3mm; padding: 4mm; break-inside: avoid; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2.2mm 1mm; border-bottom: 0.25mm solid var(--line); }
  tr:last-child td { border-bottom: 0; }
  td.amount { text-align: right; font-weight: 700; white-space: nowrap; }
  td.chosen { color: var(--orange); }
  table.sizes th { font-size: 8pt; color: var(--muted); font-weight: 600; text-align: right; padding: 1.6mm 1mm; border-bottom: 0.25mm solid var(--line); line-height: 1.25; }
  table.sizes th:first-child { text-align: left; }
  table.sizes td.chosen { background: var(--tint); border-radius: 1.5mm; }
  .dates { display: flex; flex-direction: column; gap: 2mm; }
  .date { display: flex; justify-content: space-between; align-items: center; gap: 3mm; background: var(--wash); border-radius: 2.4mm; padding: 2mm 3mm; }
  .date strong { font-size: 10.5pt; }
  .note { margin-top: 3mm; font-size: 8.3pt; color: var(--muted); }

  /* A row of photos: one height, each its own width (its own shape), together the page's width. */
  .photos { margin-top: 7mm; display: flex; flex-direction: column; gap: 3mm; }
  .row { display: flex; justify-content: center; align-items: flex-start; gap: 2.5mm; break-inside: avoid; }
  figure { margin: 0; flex: none; break-inside: avoid; }
  figure img { display: block; object-fit: cover; border-radius: 2mm; }
  figcaption { margin-top: 1mm; font-size: 7.6pt; line-height: 1.3; color: var(--muted); overflow-wrap: anywhere; }

  ol.days { margin: 0; padding: 0; list-style: none; }
  ol.days li { display: grid; grid-template-columns: 15mm 1fr; gap: 4mm; break-inside: avoid; padding-bottom: 4mm; position: relative; }
  ol.days li::before { content: ''; position: absolute; left: 7.2mm; top: 13mm; bottom: 0; width: 0.5mm; background: var(--line); }
  ol.days li:last-child::before { display: none; }
  .badge { width: 15mm; height: 13mm; border-radius: 3mm; background: var(--blue); color: #fff; text-align: center; line-height: 1.05; padding-top: 1.8mm; }
  .badge small { display: block; font-size: 6.8pt; text-transform: uppercase; letter-spacing: .08em; opacity: .9; }
  .badge b { display: block; font-size: 14pt; }
  .day strong { display: block; font-size: 11pt; line-height: 1.35; margin-top: 0.4mm; }
  .day p { margin-top: 1mm; }
  .days-photos { margin: 1mm 0 6mm; }

  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; }
  ul.marks { margin: 0; padding: 0; list-style: none; }
  ul.marks li { position: relative; padding-left: 6mm; margin: 1.4mm 0; break-inside: avoid; }
  /* Drawn marks, not glyphs: the PDF's fonts have no tick or cross. */
  ul.marks li::before { content: ''; position: absolute; left: 0.6mm; top: 1.3mm; width: 3.4mm; height: 3.4mm; border-radius: 50%; }
  ul.yes li::before { background: #E3F4EA; }
  ul.yes li::after { content: ''; position: absolute; left: 1.75mm; top: 2.05mm; width: 1.1mm; height: 1.8mm; border: solid var(--green); border-width: 0 0.45mm 0.45mm 0; transform: rotate(45deg); }
  ul.no li::before { background: #FDE8DF; }
  ul.no li::after { content: ''; position: absolute; left: 1.55mm; top: 2.8mm; width: 1.5mm; height: 0.45mm; background: var(--red); }

  .cta { margin: 8mm 11mm 0; border-radius: 4mm; background: var(--navy); color: #fff; padding: 6mm 7mm; break-inside: avoid; }
  .cta h2 { color: #fff; margin-bottom: 1.5mm; }
  .cta .eyebrow { color: #FFD9C7; }
  .cta p { color: #D7E2F5; }
  .cta .reach { font-size: 11pt; font-weight: 700; color: #fff; margin-top: 2mm; }
</style>
</head>
<body>
  <header class="letterhead">
    @if ($logo)<img src="{{ $logo }}" alt="">@endif
    <div class="company"><strong>{{ $company['name'] }}</strong>{{ $company['address'] }}@if ($company['phones'])<br>{{ $company['phones'] }}@endif @if ($company['email'])<br>{{ $company['email'] }}@endif</div>
  </header>

  @php
    $titleWords = '<span class="eyebrow">'.e(trim($destination.' · '.$duration, ' ·')).'</span><h1 class="display">'.e($title).'</h1>'
      .'<div class="chips"><span class="chip accent">'.e($groupTour ? $l('গ্রুপ ট্যুর · ফিক্সড ডিপার্চার', 'Group Tour · Fixed Departure') : $l('কাস্টমাইজড ট্রিপ', 'Customized Trip')).'</span><span class="chip">'.e($airfare).'</span><span class="chip num">'.e($package->code).'</span></div>';
  @endphp
  @if ($hero)
    <div class="hero" style="height: {{ $hero['heightMm'] }}mm">
      <img src="{{ $hero['src'] }}" alt="">
      <div class="shade"></div>
      <div class="words">{!! $titleWords !!}</div>
    </div>
  @else
    <div class="titleblock">{!! $titleWords !!}</div>
  @endif

  <div class="pad">
    <div class="facts">
      <div class="fact"><span class="label">{{ $l('সময়কাল', 'Duration') }}</span><span class="value">{{ $duration }}</span></div>
      <div class="fact"><span class="label">{{ $l('গন্তব্য', 'Destination') }}</span><span class="value">{{ $destination ?: '—' }}</span></div>
      @if ($groupTour)
        <div class="fact"><span class="label">{{ $l('পরবর্তী ডিপার্চার', 'Next departure') }}</span><span class="value num">{{ $groupTour['departures'] !== [] ? $date($groupTour['departures'][0]['date']) : $l('শিগগিরই', 'Soon') }}</span></div>
        <div class="fact price"><span class="label">{{ $l('ট্রিপল শেয়ারিং, জনপ্রতি', 'Triple sharing, per person') }}</span><span class="value num">{{ $money($yourPrice) }}</span></div>
      @else
        @if ($package->min_pax)
          <div class="fact"><span class="label">{{ $l('সর্বনিম্ন যাত্রী', 'Minimum travellers') }}</span><span class="value">{{ $travellers((int) $package->min_pax) }}</span></div>
        @elseif ($grid)
          <div class="fact"><span class="label">{{ $l('হোটেল', 'Hotel') }}</span><span class="value">{{ \App\Enums\HotelCategory::from($category)->label($locale) }}</span></div>
        @else
          <div class="fact"><span class="label">{{ $l('ট্রিপ', 'Trip') }}</span><span class="value">{{ $l('কাস্টমাইজড', 'Customized') }}</span></div>
        @endif
        <div class="fact price"><span class="label">{{ $l('জনপ্রতি', 'Per person') }}, {{ $travellers($pax) }}</span><span class="value num">{{ $money($yourPrice) }}</span></div>
      @endif
    </div>

    @if ($summary)<p class="summary">{{ $summary }}</p>@endif

    <div class="your">
      <span>
        <span class="eyebrow">{{ $l('আপনার বেছে নেওয়া', 'Your choice') }}</span><br>
        @if ($groupTour){{ $l('ট্রিপল শেয়ারিং', 'Triple sharing') }} · @elseif ($grid){{ \App\Enums\HotelCategory::from($category)->label($locale) }} {{ $l('হোটেল', 'hotel') }} · @endif{{ $travellers($pax) }}
      </span>
      <span style="text-align:right"><strong class="num">{{ $money($yourPrice) }}</strong><br><span class="small muted">{{ $l('জনপ্রতি', 'per person') }} · {{ $l('মোট', 'total') }} <span class="num">{{ $money($yourPrice * $pax) }}</span></span></span>
    </div>

    @if ($groupTour)
    @php
      ['rooms' => $rooms, 'departures' => $departures, 'pricedOn' => $pricedOn] = $groupTour;
    @endphp
    <section class="whole">
      <h2><span class="eyebrow">{{ $l('মূল্য ও তারিখ', 'Prices & dates') }}</span>{{ $l('রুম অনুযায়ী মূল্য ও ডিপার্চারের তারিখ', 'Room prices and departure dates') }}</h2>
      <div class="prices">
        <div class="card">
          <strong>{{ $l('রুম অনুযায়ী মূল্য (জনপ্রতি)', 'Price per person by room') }}</strong>@if ($pricedOn) <span class="small muted">· {{ $l('ডিপার্চার', 'departure') }} <span class="num">{{ $date($pricedOn) }}</span></span>@endif
          <table>
            <tbody>
              <tr><td>{{ $l('ট্রিপল শেয়ারিং', 'Triple sharing') }}</td><td class="num amount chosen">{{ $money($rooms['triple']) }}</td></tr>
              <tr><td>{{ $l('টুইন শেয়ারিং', 'Twin sharing') }}@if ($rates['twinSupplementPercent'] > 0) <span class="muted">(+{{ $percent($rates['twinSupplementPercent']) }})</span>@endif</td><td class="num amount">{{ $money($rooms['twin']) }}</td></tr>
              <tr><td>{{ $l('সিঙ্গেল', 'Single') }} <span class="muted">(+{{ $percent($rates['singleSupplementPercent']) }})</span></td><td class="num amount">{{ $money($rooms['single']) }}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="card">
          <strong>{{ $l('ডিপার্চারের তারিখ', 'Departure dates') }}</strong>
          {{-- Each date with its own price per person when the prices differ by date (docs/departure-prices.md). --}}
          <div class="dates" style="margin-top:2mm">
            @forelse ($departures as $day)
              <div class="date">
                <strong class="num">{{ $date($day['date']) }}@if ($day['returns']) – {{ $date($day['returns']) }}@endif</strong>
                @if ($pricedOn)<span class="num muted">({{ $money($day['price']) }})</span>@endif
              </div>
            @empty
              <p class="muted">{{ $l('পরবর্তী তারিখ শিগগিরই জানানো হবে — আমাদের জিজ্ঞাসা করুন।', 'The next dates will be announced soon — ask us.') }}</p>
            @endforelse
          </div>
        </div>
      </div>
      <p class="note">
        {{ $l('গ্রুপ ট্যুরের মূল্য নির্ধারিত; দলের আকারে বদলায় না।', 'A group tour’s price is fixed; it does not change with the group size.') }}
        {{ $l('সার্ভিস চার্জ ও ভ্যাট', 'Service charge and VAT') }} {{ $percent($config->serviceChargePercent) }}. {{ $l('মূল্য', 'Prices as of') }} {{ $date($asOf) }}{{ $l(' তারিখ অনুযায়ী; বুকিংয়ের সময়ের মূল্যই চূড়ান্ত।', '; the price at booking is final.') }}
      </p>
    </section>
    @else
    <section class="whole">
      <h2><span class="eyebrow">{{ $l('মূল্য', 'Prices') }}</span>{{ $grid ? $l('হোটেল ও দলের আকার অনুযায়ী জনপ্রতি মূল্য', 'Price per person by hotel and group size') : $l('দলের আকার অনুযায়ী জনপ্রতি মূল্য', 'Price per person by group size') }}</h2>
      <div class="card">
        <table class="sizes">
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
                  <td class="num amount{{ $chosen ? ' chosen' : '' }}">{{ $money($cell['price']) }}</td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="note">
        {{-- Triple sharing is the base price; twin and single add the package's own percentages (docs/room-rates.md). --}}
        {{ $l('মূল্য ট্রিপল শেয়ারিং রুমে জনপ্রতি।', 'Prices are per person in a triple-sharing room.') }}
        @if ($rates['twinSupplementPercent'] > 0){{ $l('টুইন শেয়ারিং রুমে +', 'A twin-sharing room adds ') }}{{ $percent($rates['twinSupplementPercent']) }}. @endif
        @if ($grid)
          {{ $l('দুই আকারের মাঝের দল ছোট আকারের মূল্য দেয়। একজনের মূল্যে সিঙ্গেল রুম ধরা আছে; দুই বা বেশি জনের সিঙ্গেল রুমে +', 'A group between two sizes pays the smaller size’s price. The price for one includes a single room; for two or more a single room adds ') }}{{ $percent($rates['singleSupplementPercent']) }}.
        @else
          {{ $l('একজন সিঙ্গেল রুম নিলে +', 'A single room adds ') }}{{ $percent($rates['singleSupplementPercent']) }}.
        @endif
        {{ $l('সার্ভিস চার্জ ও ভ্যাট', 'Service charge and VAT') }} {{ $percent($config->serviceChargePercent) }}. {{ $l('মূল্য', 'Prices as of') }} {{ $date($asOf) }}{{ $l(' তারিখ অনুযায়ী; বুকিংয়ের সময়ের মূল্যই চূড়ান্ত।', '; the price at booking is final.') }}
      </p>
    </section>
    @endif

    @include('brochures.partials.photo-rows', ['rows' => $bands[0], 'class' => 'photos'])

    @if ($itinerary->isNotEmpty())
      <section>
        <h2><span class="eyebrow">{{ $duration }}</span>{{ $l('দিনভিত্তিক ভ্রমণসূচি', 'Day-by-day itinerary') }}</h2>
        <ol class="days">
          @foreach ($itinerary as $i => $day)
            <li>
              <span class="badge num"><small>{{ $l('দিন', 'Day') }}</small><b>{{ $number($day->day_number) }}</b></span>
              <div class="day">
                @if ($pick($day->title_bn, $day->title_en))<strong>{{ $pick($day->title_bn, $day->title_en) }}</strong>@endif
                @if ($pick($day->body_bn, $day->body_en))<p>{{ $pick($day->body_bn, $day->body_en) }}</p>@endif
              </div>
            </li>
            @if ($i + 1 === $bandAfter && $bandAfter < $itinerary->count())
              </ol>
              @include('brochures.partials.photo-rows', ['rows' => $bands[1], 'class' => 'photos days-photos'])
              <ol class="days">
            @endif
          @endforeach
        </ol>
      </section>
    @endif
    @if ($itinerary->count() < 2)
      @include('brochures.partials.photo-rows', ['rows' => $bands[1], 'class' => 'photos'])
    @endif

    @if ($includes->isNotEmpty() || $excludes->isNotEmpty())
      <section>
        <h2><span class="eyebrow">{{ $l('প্যাকেজে', 'In the package') }}</span>{{ $l('যা থাকছে, যা থাকছে না', 'What is and is not included') }}</h2>
        <div class="two">
          @if ($includes->isNotEmpty())
            <div class="card">
              <strong>{{ $l('যা থাকছে', 'Included') }}</strong>
              <ul class="marks yes">@foreach ($includes as $item)<li>{{ $pick($item->text_bn, $item->text_en) }}</li>@endforeach</ul>
            </div>
          @endif
          @if ($excludes->isNotEmpty())
            <div class="card">
              <strong>{{ $l('যা থাকছে না', 'Not included') }}</strong>
              <ul class="marks no">@foreach ($excludes as $item)<li>{{ $pick($item->text_bn, $item->text_en) }}</li>@endforeach</ul>
            </div>
          @endif
        </div>
      </section>
    @endif

    @if ($gallery !== [])
      <section>
        <h2><span class="eyebrow">{{ $l('ছবিতে', 'In pictures') }}</span>{{ $l('আমাদের গ্রুপ ট্যুরের মুহূর্ত', 'Moments from our group tours') }}</h2>
        <p class="small muted">{{ $l('আমাদের আগের গ্রুপ ট্যুরগুলোর ছবি।', 'Photos from our earlier group tours.') }}</p>
        @include('brochures.partials.photo-rows', ['rows' => $gallery, 'class' => 'photos'])
      </section>
    @endif
  </div>

  <div class="cta">
    <h2><span class="eyebrow">{{ $l('বুকিং', 'Booking') }}</span>{{ $l('সিট বুক করতে যোগাযোগ করুন', 'To book your seat, contact us') }}</h2>
    <p class="reach">@if ($company['phones']){{ $company['phones'] }}@endif @if ($company['email']) · {{ $company['email'] }}@endif</p>
    <p>@if ($company['website']){{ $company['website'] }}@endif @if ($company['civilAviationNo']) · {{ $l('সিভিল এভিয়েশন নিবন্ধন নম্বর', 'Civil Aviation registration no.') }} {{ $company['civilAviationNo'] }}@endif</p>
  </div>
</body>
</html>
