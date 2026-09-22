<!doctype html>
{{-- Invoice print view, laid out the way Bhabaghure's own invoices have always read: the logo and letterhead, the
     number under a barcode, the two dates in a blue band, the lines, the total in figures and in words, what was paid
     and what is still owed, the notes, and two signatures at the foot.

     Sized in millimetres for A4, and the same page at A5. The letterhead block keeps its height whether the header is
     printed or not, so nothing below it moves; with it off the block is blank paper for a pre-printed pad.
     Data: App\Services\Invoices\InvoiceView (frozen snapshot + ledger).

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
@php($a5 = ($size ?? 'a4') === 'a5')
<style>
  {{-- A5 is the same page on half the paper: every size below carries its own A5 value. --}}
  @page { size: {{ $a5 ? 'A5' : 'A4' }}; margin: 0; }
  :root { --ink: #0F1E3A; --muted: #4A5A78; --blue: #0B5ED7; --line: #E1E7F2; --wash: #F4F8FF; --wash-line: #D6E4FF; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { background: #fff; }
  @media screen { body { background: #EEF1F6; padding: 8mm 0; } .page { margin: 0 auto; box-shadow: 0 2mm 8mm rgba(15, 30, 58, .12); } }
  .num { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .page { width: {{ $a5 ? '148mm' : '210mm' }}; min-height: {{ $a5 ? '210mm' : '297mm' }}; padding: {{ $a5 ? '4mm 6mm 5mm' : '6mm 9mm 7mm' }}; display: flex; flex-direction: column; gap: {{ $a5 ? '1mm' : '2mm' }}; background: #fff; font-size: {{ $a5 ? '6.8pt' : '9.5pt' }}; position: relative; }

  {{-- A4 reserves the exact height a pre-printed pad's header needs, printed or not, so nothing below it ever moves.
       The pads are A4; an A5 copy is always printed whole, so there its letterhead takes only the room it needs. --}}
  .letterhead { height: {{ $a5 ? 'auto' : $headerHeightMm.'mm' }}; flex: none; overflow: hidden; }
  .letterhead.on { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; border-bottom: 0.7mm solid var(--blue); padding-bottom: 3mm; }
  {{-- Smaller than the company's details beside it: the client asked for a smaller logo on 2026-09-23. --}}
  .letterhead img { width: {{ $a5 ? '36mm' : '50mm' }}; max-width: 36%; height: auto; object-fit: contain; flex-shrink: 0; }
  .company { text-align: right; display: flex; flex-direction: column; gap: 0.4mm; max-width: 62%; font-size: {{ $a5 ? '7pt' : '8.5pt' }}; color: var(--muted); line-height: 1.5; }
  .company strong { font-size: {{ $a5 ? '10.5pt' : '13.5pt' }}; font-weight: 700; color: var(--ink); line-height: 1.2; }
  .company .web { color: var(--blue); }

  {{-- The barcode and number on the left, the document's name on the right. --}}
  .title { display: flex; align-items: flex-start; justify-content: space-between; gap: 4mm; }
  .title h1 { margin: 0; font-family: 'Bricolage Grotesque', sans-serif; font-size: {{ $a5 ? '14pt' : '19pt' }}; font-weight: 800; letter-spacing: -.01em; line-height: 1; text-align: right; }
  .title .right { display: flex; flex-direction: column; align-items: flex-end; gap: 1mm; }
  .pill { font-size: {{ $a5 ? '7pt' : '8.5pt' }}; font-weight: 700; letter-spacing: .05em; padding: 1mm 3.2mm; border-radius: 999px; }
  .pill.paid { background: #E6F7EC; color: #12A150; } .pill.partial { background: #FFF1E6; color: #C2410C; }
  .pill.unpaid, .pill.void { background: #FEE2E2; color: #B91C1C; }
  .pill.draft, .pill.withdrawn { background: #EEF1F6; color: var(--muted); } .pill.valid { background: #E8F0FE; color: var(--blue); }
  .pill.accepted, .pill.booked { background: #E6F7EC; color: #12A150; } .pill.expired, .pill.declined { background: #FEE2E2; color: #B91C1C; }
  .barcode { display: flex; flex-direction: column; align-items: flex-start; gap: 0.8mm; }
  .barcode svg { display: block; height: {{ $a5 ? '8mm' : '12mm' }}; width: auto; }
  .barcode .no { font-size: {{ $a5 ? '8pt' : '10pt' }}; }
  .barcode .no strong { font-weight: 700; }

  .dates { display: flex; background: var(--blue); color: #fff; border-radius: 1mm; overflow: hidden; }
  .dates span { flex: 1; padding: {{ $a5 ? '1mm 2.4mm' : '1.8mm 4mm' }}; font-size: {{ $a5 ? '6.8pt' : '9.5pt' }}; }
  .dates span + span { text-align: right; }
  .dates b { font-weight: 700; }

  .parties { display: grid; grid-template-columns: 1.1fr 1fr; gap: 6mm; }
  .billed { display: flex; flex-direction: column; gap: 0.6mm; }
  .billed .who { font-size: {{ $a5 ? '7pt' : '9pt' }}; color: var(--muted); }
  .billed strong { font-size: {{ $a5 ? '9pt' : '11pt' }}; font-weight: 700; }
  .billed span.lines { font-size: {{ $a5 ? '7.5pt' : '9pt' }}; color: var(--muted); line-height: 1.55; }
  .meta { display: flex; flex-direction: column; gap: 1mm; align-self: start; }
  .meta div { display: flex; justify-content: space-between; gap: 4mm; font-size: {{ $a5 ? '7.5pt' : '9pt' }}; border-bottom: 0.25mm dotted var(--wash-line); padding-bottom: 0.8mm; }
  .meta div span:first-child { color: var(--muted); } .meta div span:last-child { font-weight: 600; text-align: right; }

  .label { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; font-size: {{ $a5 ? '6.5pt' : '8pt' }}; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
  .package { background: var(--wash); border: 0.25mm solid var(--wash-line); border-radius: 2.6mm; padding: {{ $a5 ? '2mm 2.6mm' : '3mm 3.7mm' }}; display: flex; flex-direction: column; gap: 0.8mm; }
  .package .label { color: var(--blue); }
  .package strong { font-size: {{ $a5 ? '9pt' : '11pt' }}; font-weight: 600; line-height: 1.3; }
  .package span.detail { font-size: {{ $a5 ? '7.5pt' : '9pt' }}; color: var(--muted); line-height: 1.55; }

  table { width: 100%; border-collapse: collapse; font-size: {{ $a5 ? '7.5pt' : '9pt' }}; }
  thead tr { background: var(--blue); color: #fff; }
  th { padding: {{ $a5 ? '1mm 2mm' : '1.5mm 2.6mm' }}; font-size: {{ $a5 ? '7pt' : '9pt' }}; font-weight: 700; text-align: center; border: 0.25mm solid var(--blue); }
  th.item { text-align: left; }
  td { padding: {{ $a5 ? '1mm 2mm' : '1.4mm 2.6mm' }}; line-height: 1.35; border: 0.25mm solid var(--line); vertical-align: top; }
  td.sl { text-align: center; width: {{ $a5 ? '8mm' : '11mm' }}; }
  td.qty { text-align: center; width: {{ $a5 ? '16mm' : '22mm' }}; }
  td.rate { text-align: right; width: {{ $a5 ? '22mm' : '30mm' }}; }
  td.amount { text-align: right; width: {{ $a5 ? '24mm' : '34mm' }}; font-weight: 600; }
  td .what { font-weight: 700; }
  td .note { display: block; font-size: {{ $a5 ? '6.5pt' : '8pt' }}; color: var(--muted); font-weight: 400; }

  {{-- Every figure under the table lines up on one right-hand column, as an invoice is read. --}}
  .totals { display: flex; flex-direction: column; gap: 0.8mm; }
  .totals .line { display: flex; justify-content: flex-end; gap: 4mm; font-size: {{ $a5 ? '7.5pt' : '9.5pt' }}; }
  .totals .line span:first-child { color: var(--muted); }
  .totals .line span.num { min-width: {{ $a5 ? '24mm' : '34mm' }}; text-align: right; font-weight: 600; }
  .totals .grand { display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; border-top: 0.4mm solid var(--ink); border-bottom: 0.4mm solid var(--ink); padding: 1.2mm 0; }
  .totals .grand .words { font-size: {{ $a5 ? '7pt' : '8.5pt' }}; font-weight: 700; }
  .totals .grand .sum { display: flex; gap: 4mm; align-items: baseline; }
  .totals .grand .sum span:first-child { font-size: {{ $a5 ? '9pt' : '11pt' }}; font-weight: 700; }
  .totals .grand .sum span.num { min-width: {{ $a5 ? '24mm' : '34mm' }}; text-align: right; font-size: {{ $a5 ? '10pt' : '12pt' }}; font-weight: 800; }
  .totals .due span:first-child { font-size: {{ $a5 ? '8.5pt' : '10.5pt' }}; font-weight: 700; color: var(--ink); }
  .totals .due span.num { font-weight: 800; color: {{ $hasDue ? '#C2410C' : '#12A150' }}; }
  .received { font-size: {{ $a5 ? '7pt' : '8.5pt' }}; display: flex; flex-direction: column; gap: 0.4mm; }
  .received b { font-weight: 700; }
  .received span.break { color: var(--muted); }

  .stack { display: flex; flex-direction: column; gap: 2.5mm; }
  .stack > div { display: flex; flex-direction: column; gap: 1mm; }
  .stack span.row, .stack span.muted { font-size: {{ $a5 ? '7.5pt' : '9pt' }}; line-height: 1.5; }
  .stack span.row .num { color: var(--muted); }
  .stack span.muted { color: var(--muted); }
  .notes { display: flex; flex-direction: column; gap: 0.8mm; break-inside: avoid; }
  .notes h2 { margin: 0; font-size: {{ $a5 ? '8.5pt' : '10.5pt' }}; font-weight: 700; }
  .notes span.t { font-size: {{ $a5 ? '7.5pt' : '9pt' }}; line-height: 1.5; white-space: pre-line; }
  .how-to-pay { display: flex; flex-direction: column; gap: 0.6mm; border: 0.25mm solid var(--line); border-radius: 2mm; padding: 2mm 3mm; break-inside: avoid; }
  .how-to-pay span.t { font-size: {{ $a5 ? '7pt' : '8.8pt' }}; line-height: 1.45; overflow-wrap: anywhere; }

  {{-- Both signatures sit at the foot of the last page, whatever length the invoice ran to. --}}
  footer { margin-top: auto; padding-top: {{ $a5 ? '4mm' : '6mm' }}; display: flex; flex-direction: column; gap: 2.5mm; }
  footer .terms { display: flex; flex-direction: column; gap: 0.6mm; }
  footer .terms span.t { font-size: {{ $a5 ? '6.5pt' : '8pt' }}; color: var(--muted); line-height: 1.5; }
  footer .signs { display: flex; justify-content: space-between; gap: 10mm; padding-top: {{ $a5 ? '2mm' : '3.5mm' }}; }
  footer .sign { flex: 1; max-width: {{ $a5 ? '46mm' : '64mm' }}; display: flex; flex-direction: column; gap: 1mm; text-align: center; }
  footer .sign .line { border-top: 0.3mm dashed var(--muted); }
  footer .sign span { font-size: {{ $a5 ? '7.5pt' : '9.5pt' }}; }
  footer .thanks { text-align: center; font-size: {{ $a5 ? '6.5pt' : '8pt' }}; color: var(--muted); line-height: 1.55; }

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
        {{-- email_off: Cloudflare otherwise swaps e-mail addresses for "[email protected]" on pages it serves, and the
             script that swaps them back does not run in the invoice view (seen 2026-09-19). --}}
        <span><!--email_off-->@if ($company['email'])Email: {{ $company['email'] }}<br>@endif<!--/email_off--> @if ($company['phones'])Mobile: {{ $company['phones'] }}<br>@endif @if ($company['civilAviationNo'])Tax No: Civil Aviation No- {{ $company['civilAviationNo'] }}@endif</span>
        @if ($company['website'])<span class="web num">{{ $company['website'] }}</span>@endif
      </div>
    </header>
  @else
    {{-- Left blank on purpose: the pre-printed pad's letterhead goes here. --}}
    <div class="letterhead" data-region="letterhead" aria-hidden="true"></div>
  @endif

  <div class="title">
    <div class="barcode">
      @if ($barcode){!! $barcode !!}@endif
      <span class="no"><strong>{{ $kind === 'quotation' ? 'Quotation Number' : 'Invoice Number' }}</strong> <span class="num">{{ $number }}</span></span>
    </div>
    <div class="right">
      <h1>{{ $kind === 'quotation' ? 'QUOTATION' : 'INVOICE' }}</h1>
      <span class="pill {{ $status['key'] }}">{{ $status['label'] }}</span>
    </div>
  </div>

  <div class="dates" data-region="dates">
    <span>{{ $kind === 'quotation' ? 'Quotation Date' : 'Invoice Date' }}: <b class="num">{{ $documentDate }}</b></span>
    <span>{{ $kind === 'quotation' ? 'Valid Until' : 'Due Date' }}: <b class="num">{{ $dueDate }}</b></span>
  </div>

  <section class="parties">
    <div class="billed">
      <span class="who">{{ $kind === 'quotation' ? 'Prepared for' : 'Invoice To' }}</span>
      <strong>{{ $billed['name'] }}</strong>
      <span class="lines"><!--email_off-->@foreach ($billed['lines'] as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach<!--/email_off--></span>
    </div>
    <div class="meta">
      @foreach ($meta as [$label, $value])
        <div><span>{{ $label }}</span><span>{{ $value }}</span></div>
      @endforeach
    </div>
  </section>

  @if ($package['title'])
    <section class="package">
      <span class="label">{{ $packageLabel ?? 'Package' }}</span>
      <strong>{{ $package['title'] }}@if ($package['code']) <span class="num" style="font-weight:400;color:var(--muted)">· {{ $package['code'] }}</span>@endif</strong>
      @if ($package['detail'])<span class="detail">{{ $package['detail'] }}</span>@endif
    </section>
  @endif

  <table>
    <thead><tr><th>Sl.</th><th class="item">Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
    <tbody>
      @foreach ($items as $i => $item)
        <tr>
          <td class="sl num">{{ \App\Support\Numerals::localizeDigits((string) ($i + 1), $locale) }}</td>
          <td><span class="what">{{ $item['title'] }}</span>@if ($item['note'])<span class="note">{{ $item['note'] }}</span>@endif</td>
          <td class="qty num">{{ $item['quantity'] }}</td>
          <td class="rate num">{{ $item['rate'] }}</td>
          <td class="amount num">{{ $item['amount'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <section class="totals">
    @foreach ($totals as [$label, $amount])
      <div class="line"><span>{{ $label }}</span><span class="num">{{ $amount }}</span></div>
    @endforeach
    {{-- The figure written twice, in words and in digits, so a changed digit shows. --}}
    <div class="grand">
      <span class="words">In words: {{ $amountInWords }}</span>
      <span class="sum"><span>Total</span><span class="num">{{ $total }}</span></span>
    </div>
    @if ($kind !== 'quotation')
      @foreach ($paymentRows as [$label, $amount])
        <div class="line"><span>{{ $label }}</span><span class="num">{{ $amount }}</span></div>
      @endforeach
      <div class="line due"><span>Amount Due</span><span class="num">{{ $due }}</span></div>
    @endif
  </section>

  @if ($kind !== 'quotation' && $paymentRows !== [])
    <div class="received" data-region="received">
      <span><b>Total Received:</b> <span class="num">{{ $paid }}</span></span>
      <span class="break">Payment breakdown · {{ $number }}: @foreach ($paymentRows as $row)<span class="num">{{ $row[1] }}</span>@if (! $loop->last) · @endif @endforeach</span>
    </div>
  @endif

  @if ($travellers || $kind === 'quotation')
    <div class="stack">
      @if ($travellers)
        <div>
          <span class="label">Travellers</span>
          @foreach ($travellers as $i => $traveller)
            <span class="row">{{ \App\Support\Numerals::localizeDigits((string) ($i + 1), $locale) }}. {{ $traveller['name'] }}@if ($traveller['passport']) — <span class="num">{{ $traveller['passport'] }}@if ($traveller['expiry']) · expires {{ $traveller['expiry'] }}@endif</span>@endif</span>
          @endforeach
        </div>
      @endif
      @if ($kind === 'quotation')
        <div data-region="validity">
          <span class="label">Validity</span>
          <span class="muted">{{ $validUntil }}</span>
        </div>
      @endif
    </div>
  @endif

  @if (! empty($howToPay))
    {{-- Phase 8 §4.F: bank transfer, the payment link while the built-in checkout is off, bKash with its charge. --}}
    <section class="how-to-pay" data-region="how-to-pay">
      <span class="label">How to pay</span>
      @foreach ($howToPay as $line)
        <span class="t">· {{ $line }}</span>
      @endforeach
      <span class="t">Write the booking or quotation number as the reference; on the card payment form, after your name.</span>
    </section>
  @endif

  @if (! empty($note))
    <section class="notes" data-region="notes">
      <h2>Notes / Terms</h2>
      <span class="t">{{ $note }}</span>
    </section>
  @endif

  <footer>
    <div class="terms">
      @foreach ($terms as $term)
        <span class="t">· {{ $term }}</span>
      @endforeach
    </div>
    <div class="signs">
      <div class="sign"><div class="line"></div><span>Customer Signature</span></div>
      <div class="sign"><div class="line"></div><span>Authorized Signature</span></div>
    </div>
    <div class="thanks">
      Thank you for choosing {{ $company['name'] }}. We are thrilled to be part of your journey and consider it a pleasure to serve valued guests like you!
      @if ($company['notificationsWhatsapp'])<br><span data-region="notifications-number">Notifications: {{ $company['notificationsWhatsapp'] }}</span>@endif
    </div>
  </footer>

  @if ($voidReason !== null)
    <div class="void-mark" aria-hidden="true"><span>VOID</span></div>
  @endif
</main>
</body>
</html>
