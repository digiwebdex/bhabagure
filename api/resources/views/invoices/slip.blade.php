<!doctype html>
{{-- The counter documents (docs/phase-9-accounts.md §5), 80mm wide so they print on the same roll as a till receipt:

     · slip     — what was sold and what is still owed, handed over with the money.
     · delivery — what is being handed over (tickets, passports, visas) with a line to sign for it. No prices: a
                  delivery receipt travels with the documents and is not a demand for money.

     Data: App\Services\Invoices\InvoiceView, the same frozen snapshot the A4 invoice prints from. --}}
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
  {{-- The roll has no fixed length: the page is as long as the slip turns out to be. --}}
  @page { size: 80mm auto; margin: 0; }
  :root { --ink: #0F1E3A; --muted: #4A5A78; --line: #C9D4E8; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { background: #fff; }
  @media screen { body { background: #EEF1F6; padding: 6mm 0; } .slip { margin: 0 auto; box-shadow: 0 2mm 8mm rgba(15, 30, 58, .12); } }
  .num { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .slip { width: 80mm; padding: 5mm 4mm 7mm; background: #fff; font-size: 8pt; line-height: 1.5; display: flex; flex-direction: column; gap: 2mm; }
  header { text-align: center; display: flex; flex-direction: column; gap: 0.6mm; }
  header strong { font-size: 11pt; font-weight: 700; line-height: 1.25; }
  header span { font-size: 7pt; color: var(--muted); }
  h1 { margin: 2mm 0 0; font-family: 'Bricolage Grotesque', sans-serif; font-size: 9.5pt; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; text-align: center; }
  .rule { border-top: 0.3mm dashed var(--line); }
  .meta { display: flex; flex-direction: column; gap: 0.6mm; font-size: 7.5pt; }
  .meta div { display: flex; justify-content: space-between; gap: 3mm; }
  .meta span:first-child { color: var(--muted); }
  table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
  th { text-align: left; font-size: 7pt; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); font-weight: 700; padding: 1mm 0; border-bottom: 0.3mm solid var(--line); }
  td { padding: 1mm 0; vertical-align: top; border-bottom: 0.2mm dotted var(--line); }
  td .note { display: block; font-size: 6.5pt; color: var(--muted); }
  .r { text-align: right; } .c { text-align: center; width: 10mm; }
  .totals { display: flex; flex-direction: column; gap: 0.8mm; font-size: 8pt; }
  .totals div { display: flex; justify-content: space-between; gap: 3mm; }
  .totals .grand { font-size: 10pt; font-weight: 800; padding-top: 1mm; border-top: 0.3mm solid var(--ink); }
  .totals .due { font-weight: 700; }
  .sign { margin-top: 8mm; display: flex; flex-direction: column; gap: 1mm; }
  .sign .line { border-bottom: 0.3mm solid var(--ink); height: 8mm; }
  .sign span { font-size: 7pt; color: var(--muted); }
  footer { text-align: center; font-size: 6.5pt; color: var(--muted); line-height: 1.6; }
  .void { text-align: center; font-size: 12pt; font-weight: 800; letter-spacing: .2em; color: #B42318; }
</style>
</head>
<body>
<div class="slip">
  <header>
    <strong>{{ $company['name'] }}</strong>
    @if ($company['address'])<span>{{ $company['address'] }}</span>@endif
    @if ($company['phones'])<span class="num">{{ $company['phones'] }}</span>@endif
  </header>

  <h1>{{ $delivery ? 'Delivery Receipt' : ($kind === 'quotation' ? 'Quotation' : 'Invoice') }}</h1>
  <div class="rule"></div>

  <div class="meta">
    <div><span>{{ $kind === 'quotation' ? 'Quotation' : 'Invoice' }}</span><span class="num">{{ $number }}</span></div>
    <div><span>Date</span><span class="num">{{ $slipDate }}</span></div>
    @if ($billed['name'])<div><span>Customer</span><span>{{ $billed['name'] }}</span></div>@endif
    {{-- email_off: kept as written, not hidden by Cloudflare (see invoice.blade.php). --}}
    <!--email_off-->
    @foreach ($billed['lines'] as $line)
      <div><span></span><span class="num">{{ $line }}</span></div>
    @endforeach
    <!--/email_off-->
  </div>
  <div class="rule"></div>

  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th class="c">Qty</th>
        @unless ($delivery)<th class="r">Amount</th>@endunless
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $item)
        <tr>
          <td>{{ $item['title'] }}@if ($item['note'])<span class="note">{{ $item['note'] }}</span>@endif</td>
          <td class="c num">{{ $item['quantity'] }}</td>
          @unless ($delivery)<td class="r num">{{ $item['amount'] }}</td>@endunless
        </tr>
      @endforeach
    </tbody>
  </table>

  @unless ($delivery)
    <div class="totals">
      @foreach ($totals as [$label, $amount])
        <div><span>{{ $label }}</span><span class="num">{{ $amount }}</span></div>
      @endforeach
      <div class="grand"><span>Total</span><span class="num">{{ $total }}</span></div>
      @if ($kind !== 'quotation')
        <div><span>Paid</span><span class="num">{{ $paid }}</span></div>
        <div class="due"><span>Balance due</span><span class="num">{{ $due }}</span></div>
      @endif
    </div>
  @endunless

  @if ($delivery)
    {{-- Whoever takes the documents signs here; that signature is the whole point of the page. --}}
    <div class="sign">
      <div class="line"></div>
      <span>Received by · name, signature and date</span>
    </div>
  @endif

  @if ($voidReason !== null)
    <div class="void">VOID</div>
  @endif

  <div class="rule"></div>
  <footer>
    @if ($delivery)
      Please check every document before signing.<br>
    @endif
    Thank you for travelling with us.<br>
    @if ($company['website'])<span class="num">{{ $company['website'] }}</span>@endif
  </footer>
</div>
</body>
</html>
