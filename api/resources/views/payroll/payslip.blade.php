<!doctype html>
{{-- Payslip (docs/phase-7-hr-attendance-bonus-wallet.md §6): one person's finalised month, frozen in payroll_items.
     A4, on the shared letterhead. Data: App\Services\Payroll\PayslipPdf. --}}
@php
    $en = $locale === 'en';
    $l = fn (string $bn, string $en_) => $en ? $en_ : $bn;
    $money = fn ($amount) => \App\Support\Numerals::bdt($amount, $locale);
    $number = fn ($value) => \App\Support\Numerals::number($value, $locale);
    $date = fn (string $iso) => \App\Support\Numerals::date($iso, $locale);
    $monthName = $date($month->format('Y-m-d'));
    $monthLabel = preg_replace('/^\S+\s/u', '', $monthName);
    $totals = $item->totals;
    $dayRate = (float) $item->day_rate;
    $reduced = (float) ($totals['reduced_days'] ?? 0);
    $pay = (int) ($rules['late_early_pay_percent'] ?? 50);
@endphp
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $l('পে-স্লিপ', 'Payslip') }} · {{ $item->staff->name }} · {{ $monthLabel }}</title>
@if ($forPdf)
<!--bh:fonts-->
@else
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&display=swap" rel="stylesheet">
@endif
<style>
  @page { size: A4; margin: 0; }
  :root { --ink: #0F1E3A; --muted: #4A5A78; --blue: #0B5ED7; --line: #E1E7F2; --wash: #F4F8FF; }
  * { box-sizing: border-box; }
  html, body { margin: 0; color: var(--ink); font-family: 'Hind Siliguri', 'Bricolage Grotesque', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .num { font-family: 'Bricolage Grotesque', 'Hind Siliguri', sans-serif; }
  .page { width: 210mm; min-height: 297mm; padding: 8mm 11mm; display: flex; flex-direction: column; gap: 4mm; font-size: 9.5pt; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 7mm; border-bottom: 0.7mm solid var(--blue); padding-bottom: 3mm; }
  .letterhead img { width: 58mm; height: auto; }
  .company { text-align: right; font-size: 8.5pt; color: var(--muted); line-height: 1.5; }
  .company strong { display: block; font-size: 13pt; color: var(--ink); }
  h1 { margin: 0; font-family: 'Bricolage Grotesque', sans-serif; font-size: 17pt; color: var(--blue); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
  .box { background: var(--wash); border-radius: 2.6mm; padding: 3mm 3.7mm; display: flex; flex-direction: column; gap: 1.2mm; }
  .label { font-size: 8pt; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 1.5mm 2.4mm; border-bottom: 0.25mm solid var(--line); }
  td.amount { text-align: right; }
  tr.total td { border-top: 0.5mm solid var(--ink); font-weight: 700; font-size: 11pt; }
  .muted { color: var(--muted); }
  .foot { margin-top: auto; font-size: 8pt; color: var(--muted); }
</style>
</head>
<body>
<div class="page">
  <header class="letterhead">
    @if ($logo)<img src="{{ $logo }}" alt="">@endif
    <div class="company"><strong>{{ $company['name'] }}</strong>{{ $company['address'] }}@if ($company['phones'])<br>{{ $company['phones'] }}@endif</div>
  </header>

  <h1>{{ $l('পে-স্লিপ', 'Payslip') }} · {{ $monthLabel }}</h1>

  <div class="grid">
    <div class="box">
      <span class="label">{{ $l('কর্মী', 'Staff member') }}</span>
      <strong>{{ $item->staff->name }}</strong>
      <span class="num">{{ $item->staff->employee_code }}</span>
      @if ($item->staff->profile?->designation)<span class="muted">{{ $item->staff->profile->designation }}</span>@endif
    </div>
    <div class="box">
      <span class="label">{{ $l('উপস্থিতি', 'Attendance') }}</span>
      <span>{{ $l('কর্মদিবস', 'Working days') }}: <span class="num">{{ $number($totals['working_days'] ?? 0) }}</span> · {{ $l('পূর্ণ', 'Full') }}: <span class="num">{{ $number($totals['full'] ?? 0) }}</span></span>
      <span>{{ $l('দেরি বা আগে', 'Late or early') }}: <span class="num">{{ $number($reduced) }}</span> · {{ $l('অনুপস্থিত', 'Absent') }}: <span class="num">{{ $number($totals['absent_days'] ?? 0) }}</span></span>
      <span>{{ $l('বেতনসহ ছুটি', 'Paid leave') }}: <span class="num">{{ $number($totals['leave_paid'] ?? 0) }}</span> · {{ $l('বেতনবিহীন ছুটি', 'Unpaid leave') }}: <span class="num">{{ $number($totals['leave_unpaid'] ?? 0) }}</span></span>
    </div>
  </div>

  <table>
    <tr><td>{{ $l('মূল বেতন', 'Base salary') }}</td><td class="amount num">{{ $money($item->base) }}</td></tr>
    <tr><td class="muted">{{ $l('দৈনিক হার', 'Day rate') }} ({{ $money($item->base) }} ÷ {{ $number($rules['working_days_per_month'] ?? 26) }})</td><td class="amount num muted">{{ $money(round($dayRate, 2)) }}</td></tr>
    @if (($totals['absent_days'] ?? 0) > 0)
      <tr><td>{{ $l('অনুপস্থিতি', 'Absence') }} ({{ $number($totals['absent_days']) }} × {{ $money(round($dayRate, 2)) }})</td><td class="amount num">− {{ $money(round($dayRate * $totals['absent_days'], 2)) }}</td></tr>
    @endif
    @if ($reduced > 0)
      <tr><td>{{ $l('দেরি বা আগে যাওয়া', 'Late or early days') }} ({{ $number($reduced) }} × {{ $number(100 - $pay) }}%)</td><td class="amount num">− {{ $money(round($dayRate * $reduced * (100 - $pay) / 100, 2)) }}</td></tr>
    @endif
    @if (($totals['not_employed_working_days'] ?? 0) > 0)
      <tr><td>{{ $l('চাকরির বাইরের কর্মদিবস', 'Working days before joining or after leaving') }} ({{ $number($totals['not_employed_working_days']) }})</td><td class="amount num">− {{ $money(round($dayRate * $totals['not_employed_working_days'], 2)) }}</td></tr>
    @endif
    @foreach ($adjustments as $adjustment)
      <tr><td>{{ $adjustment->reason }}</td><td class="amount num">{{ (float) $adjustment->amount < 0 ? '− '.$money(abs((float) $adjustment->amount)) : '+ '.$money($adjustment->amount) }}</td></tr>
    @endforeach
    <tr class="total"><td>{{ $l('প্রদেয়', 'Payable') }}</td><td class="amount num">{{ $money($item->payable) }}</td></tr>
  </table>

  @if ($item->paid_at)
    <p class="muted">{{ $l('পরিশোধ', 'Paid') }}: {{ $date($item->paid_at->timezone('Asia/Dhaka')->toDateString()) }}@if ($item->transaction?->reference_label) · {{ $item->transaction->reference_label }}@endif</p>
  @endif

  <p class="foot">{{ $l('এই পে-স্লিপ হাজিরা ডিভাইসের পাঞ্চ ও মাসের নিয়ম থেকে তৈরি, মাস চূড়ান্ত করার সময়ের হিসাবে।', 'This payslip is figured from the attendance device’s punches and the month’s rules, as they stood when the month was finalised.') }}</p>
</div>
</body>
</html>
