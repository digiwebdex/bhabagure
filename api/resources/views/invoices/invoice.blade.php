<!doctype html>
{{-- Invoice print view (_design/Bhabaghure Invoice.dc.html). Sized in millimetres for A4. The letterhead block has a
     fixed height whether the header is on or off, so nothing below it moves; with it off the block is blank for a
     pre-printed pad. Data: App\Services\Invoices\InvoiceView (frozen snapshot + ledger).
     The same page prints quotations ($kind = 'quotation', App\Services\Quotations\QuotationView): the letterhead and
     lines are shared; a quotation shows its validity instead of payments, and never travellers or passports. --}}
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $number }} · {{ $company['name'] }}</title>
@if ($forPdf)
<!--bh:fonts-->
@else
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&display=swap" rel="stylesheet">
@endif
<style>
  @page { size: A4; margin: 0; }
  :root { --ink: #0F1E3A; --muted: #4A5A78; --blue: #0B5ED7; --line: #E1E7F2; --wash: #F4F8FF; --wash-line: #D6E4FF; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { background: #fff; }
  @media screen { body { background: #EEF1F6; padding: 8mm 0; } .page { margin: 0 auto; box-shadow: 0 2mm 8mm rgba(15, 30, 58, .12); } }
  .num { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .page { width: 210mm; min-height: 297mm; padding: 6mm 9mm 7mm; display: flex; flex-direction: column; gap: 2.2mm; background: #fff; font-size: 9.5pt; position: relative; }
  .letterhead { height: {{ $headerHeightMm }}mm; flex: none; overflow: hidden; }
  .letterhead.on { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; border-bottom: 0.7mm solid var(--blue); padding-bottom: 3mm; }
  .letterhead img { width: 66mm; max-width: 46%; height: auto; object-fit: contain; flex-shrink: 0; }
  .company { text-align: right; display: flex; flex-direction: column; gap: 0.5mm; max-width: 62%; font-size: 8.5pt; color: var(--muted); line-height: 1.5; }
  .company strong { font-size: 13.5pt; font-weight: 700; color: var(--ink); line-height: 1.2; }
  .company .web { color: var(--blue); }
  .title { height: 10mm; flex: none; display: flex; align-items: center; justify-content: space-between; gap: 3mm; }
  .title h1 { margin: 0; font-family: 'Bricolage Grotesque', sans-serif; font-size: 17pt; font-weight: 800; letter-spacing: -.01em; color: var(--blue); line-height: 1; }
  .title .no { display: flex; align-items: center; gap: 3mm; font-size: 9.5pt; color: var(--muted); }
  .pill { font-size: 8.5pt; font-weight: 700; letter-spacing: .05em; padding: 1mm 3.2mm; border-radius: 999px; }
  .pill.paid { background: #E6F7EC; color: #12A150; } .pill.partial { background: #FFF1E6; color: #C2410C; }
  .pill.unpaid, .pill.void { background: #FEE2E2; color: #B91C1C; }
  .pill.draft, .pill.withdrawn { background: #EEF1F6; color: var(--muted); } .pill.valid { background: #E8F0FE; color: var(--blue); }
  .pill.accepted, .pill.booked { background: #E6F7EC; color: #12A150; } .pill.expired, .pill.declined { background: #FEE2E2; color: #B91C1C; }
  .label { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; font-size: 8pt; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
  .grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 6mm; }
  .billed { display: flex; flex-direction: column; gap: 1mm; }
  .billed strong { font-size: 11pt; font-weight: 600; }
  .billed span.lines { font-size: 9pt; color: var(--muted); line-height: 1.55; }
  .meta { display: flex; flex-direction: column; gap: 1.8mm; }
  .meta div { display: flex; justify-content: space-between; gap: 4mm; font-size: 9pt; border-bottom: 0.25mm dotted var(--wash-line); padding-bottom: 1mm; }
  .meta div span:first-child { color: var(--muted); } .meta div span:last-child { font-weight: 600; text-align: right; }
  .barcode { display: flex; flex-direction: column; align-items: flex-end; gap: 0.6mm; margin-top: 0.8mm; }
  .barcode svg { display: block; }
  .barcode span { font-family: 'Bricolage Grotesque', sans-serif; font-size: 8pt; letter-spacing: .22em; font-weight: 600; }
  .package { background: var(--wash); border: 0.25mm solid var(--wash-line); border-radius: 2.6mm; padding: 3mm 3.7mm; display: flex; flex-direction: column; gap: 0.8mm; }
  .package .label { color: var(--blue); }
  .package strong { font-size: 11pt; font-weight: 600; line-height: 1.3; }
  .package span.detail { font-size: 9pt; color: var(--muted); line-height: 1.55; }
  table { width: 100%; border-collapse: collapse; font-size: 9pt; }
  thead tr { background: var(--ink); color: #fff; text-align: left; }
  th { padding: 1.6mm 2.6mm; font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; }
  td { padding: 1.4mm 2.6mm; line-height: 1.35; border-bottom: 0.25mm solid var(--line); }
  tbody tr:nth-child(even) { background: #FBFCFE; }
  .c { text-align: center; width: 16mm; } .r { text-align: right; } .rate { width: 29mm; } .amount { width: 32mm; font-weight: 600; }
  td .note { font-size: 8pt; color: var(--muted); }
  .summary { display: grid; grid-template-columns: 1.25fr 1fr; gap: 6mm; align-items: start; }
  .stack { display: flex; flex-direction: column; gap: 3.5mm; }
  .stack > div { display: flex; flex-direction: column; gap: 1.2mm; }
  .stack span.row { font-size: 9pt; line-height: 1.5; }
  .stack span.row .num { color: var(--muted); }
  .stack span.muted { font-size: 9pt; line-height: 1.55; color: var(--muted); }
  .totals { display: flex; flex-direction: column; gap: 1.8mm; }
  .totals div { display: flex; justify-content: space-between; gap: 4mm; font-size: 9.5pt; }
  .totals div span:first-child { color: var(--muted); } .totals div span.num { font-weight: 600; }
  .totals .rule { height: 0.5mm; background: var(--ink); margin: 1mm 0; }
  .totals .grand { align-items: baseline; } .totals .grand span:first-child { font-size: 11pt; font-weight: 700; color: var(--ink); }
  .totals .grand .num { font-size: 15pt; font-weight: 800; color: #F0500F; }
  .totals .paid { background: #E6F7EC; border-radius: 2mm; padding: 2mm 2.6mm; margin-top: 1mm; }
  .totals .paid span { color: #12A150 !important; font-weight: 700; }
  .totals .due { padding: 0 2.6mm; } .totals .due .num { font-weight: 700; color: {{ $hasDue ? '#C2410C' : 'var(--ink)' }}; }
  footer { margin-top: auto; display: grid; grid-template-columns: 1.3fr 1fr; gap: 5mm; align-items: end; border-top: 0.7mm solid var(--blue); padding-top: 2.4mm; }
  footer .terms { display: flex; flex-direction: column; gap: 0.8mm; }
  footer .terms span.t { font-size: 9pt; color: var(--muted); line-height: 1.5; }
  footer .sign { display: flex; flex-direction: column; gap: 0.8mm; align-items: flex-end; text-align: right; font-size: 8pt; color: var(--muted); line-height: 1.5; }
  footer .sign .line { width: 100%; max-width: 48mm; border-bottom: 0.25mm solid var(--ink); height: 5mm; }
  .void-mark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
  .void-mark span { transform: rotate(-24deg); font-family: 'Bricolage Grotesque', sans-serif; font-size: 72pt; font-weight: 800; color: rgba(185, 28, 28, .14); letter-spacing: .1em; }
</style>
</head>
<body>
<main class="page" data-{{ $kind }}="{{ $number }}">
  @if ($header)
    <header class="letterhead on" data-region="letterhead">
      @if ($logo)<img src="{{ $logo }}" alt="{{ $company['name'] }}">@endif
      <div class="company">
        <strong>{{ $company['name'] }}</strong>
        <span>{{ $company['address'] }}</span>
        <span>@if ($company['email'])Email: {{ $company['email'] }}<br>@endif @if ($company['phones'])Mobile: {{ $company['phones'] }}<br>@endif @if ($company['civilAviationNo'])Civil Aviation No: {{ $company['civilAviationNo'] }}@endif</span>
        @if ($company['website'])<span class="web num">{{ $company['website'] }}</span>@endif
      </div>
    </header>
  @else
    {{-- Left blank on purpose: the pre-printed pad's letterhead goes here. --}}
    <div class="letterhead" data-region="letterhead" aria-hidden="true"></div>
  @endif

  <div class="title">
    <h1>{{ $kind === 'quotation' ? 'QUOTATION' : 'INVOICE' }}</h1>
    <span class="no"><span>{{ $kind === 'quotation' ? 'কোটেশন' : 'ইনভয়েস' }} · <span class="num">{{ $number }}</span></span><span class="pill {{ $status['key'] }}">{{ $status['label'] }}</span></span>
  </div>

  <section class="grid">
    <div class="billed">
      <span class="label">{{ $kind === 'quotation' ? 'Prepared for · গ্রাহক' : 'Billed to · গ্রাহক' }}</span>
      <strong>{{ $billed['name'] }}</strong>
      <span class="lines">@foreach ($billed['lines'] as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach</span>
    </div>
    <div class="meta">
      @foreach ($meta as [$label, $value])
        <div><span>{{ $label }}</span><span>{{ $value }}</span></div>
      @endforeach
      @if ($barcode)
        <div class="barcode" style="border:0;padding:0">{!! $barcode !!}<span>{{ $number }}</span></div>
      @endif
    </div>
  </section>

  <section class="package">
    <span class="label">Package · প্যাকেজ</span>
    <strong>{{ $package['title'] }}@if ($package['code']) <span class="num" style="font-weight:400;color:var(--muted);font-size:9pt">· {{ $package['code'] }}</span>@endif</strong>
    @if ($package['detail'])<span class="detail">{{ $package['detail'] }}</span>@endif
  </section>

  <table>
    <thead><tr><th>Description · বিবরণ</th><th class="c">Qty</th><th class="r rate">Rate</th><th class="r amount">Amount</th></tr></thead>
    <tbody>
      @foreach ($items as $item)
        <tr>
          <td><span style="font-weight:500">{{ $item['title'] }}</span>@if ($item['note']) <span class="note">{{ $item['note'] }}</span>@endif</td>
          <td class="c num">{{ $item['quantity'] }}</td>
          <td class="r rate num">{{ $item['rate'] }}</td>
          <td class="r amount num">{{ $item['amount'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <section class="summary">
    <div class="stack">
      @if ($travellers)
        <div>
          <span class="label">Travellers · যাত্রী</span>
          @foreach ($travellers as $i => $traveller)
            <span class="row">{{ \App\Support\Numerals::localizeDigits((string) ($i + 1), $locale) }}. {{ $traveller['name'] }}@if ($traveller['passport']) — <span class="num">{{ $traveller['passport'] }}@if ($traveller['expiry']) · মেয়াদ {{ $traveller['expiry'] }}@endif</span>@endif</span>
          @endforeach
        </div>
      @endif
      @if ($kind === 'quotation')
        <div data-region="validity">
          <span class="label">Validity · মেয়াদ</span>
          <span class="muted">{{ $validUntil }}</span>
        </div>
      @else
        <div>
          <span class="label">Payment · পেমেন্ট</span>
          @forelse ($payments as $payment)
            <span class="muted">{{ $payment }}</span>
          @empty
            <span class="muted">{{ $locale === 'bn' ? 'এখনো কোনো পেমেন্ট পাওয়া যায়নি।' : 'No payment received yet.' }}</span>
          @endforelse
        </div>
      @endif
    </div>
    <div class="totals">
      @foreach ($totals as [$label, $amount])
        <div><span>{{ $label }}</span><span class="num">{{ $amount }}</span></div>
      @endforeach
      <div class="rule"></div>
      <div class="grand"><span>সর্বমোট · Total</span><span class="num">{{ $total }}</span></div>
      @if ($kind !== 'quotation')
        <div class="paid"><span>পরিশোধিত · Paid</span><span class="num">{{ $paid }}</span></div>
        <div class="due"><span>বকেয়া · Balance due</span><span class="num">{{ $due }}</span></div>
      @endif
    </div>
  </section>

  <footer>
    <div class="terms">
      <span class="label">Terms · শর্তাবলী</span>
      @foreach ($terms as $term)
        <span class="t">· {{ $term }}</span>
      @endforeach
    </div>
    <div class="sign">
      <span class="line"></span>
      <span>অনুমোদিত স্বাক্ষর · Authorised signature</span>
      <span>{{ $company['phones'] }}@if ($company['website']) · {{ $company['website'] }}@endif @if ($company['facebook'])<br>{{ $company['facebook'] }}@endif</span>
      @if ($company['notificationsWhatsapp'])<span data-region="notifications-number">নোটিফিকেশন নম্বর · Notifications: {{ $company['notificationsWhatsapp'] }}</span>@endif
    </div>
  </footer>

  @if ($voidReason !== null)
    <div class="void-mark" aria-hidden="true"><span>VOID</span></div>
  @endif
</main>
</body>
</html>
