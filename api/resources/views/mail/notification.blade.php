<!doctype html>
{{-- Notification email (App\Mail\NotificationMail). Plain, table-free layout that survives Gmail and Outlook. --}}
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $senderLine }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#F4F6FB;font-family:'Hind Siliguri',Arial,sans-serif;color:#0F1E3A">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #E2E7F0;border-radius:14px;overflow:hidden">
    <div style="padding:16px 22px;border-bottom:3px solid #0B5ED7;font-size:17px;font-weight:700">{{ $senderLine }}</div>
    <div style="padding:20px 22px;font-size:15px;line-height:1.65">
      @foreach ($lines as $line)
        @if (trim($line) === '')
          <div style="height:10px"></div>
        @elseif (preg_match('#^https?://\S+$#', trim($line)))
          <div><a href="{{ trim($line) }}" style="color:#0B5ED7;word-break:break-all">{{ trim($line) }}</a></div>
        @else
          <div>{{ $line }}</div>
        @endif
      @endforeach
    </div>
    @if ($toCustomer && ($notificationsNumber || $mainNumber))
      <div style="margin:0 22px 18px;padding:12px 14px;background:#F4F8FF;border:1px solid #D6E4FF;border-radius:10px;font-size:13.5px;line-height:1.6">
        @if ($notificationsNumber)
          @if ($locale === 'en')
            Automated WhatsApp messages about your booking come only from our notifications number <strong>{{ $notificationsNumber }}</strong>.
            @if ($mainNumber) To talk to us, call or WhatsApp our main line <strong>{{ $mainNumber }}</strong>. @endif
          @else
            আপনার বুকিং নিয়ে স্বয়ংক্রিয় WhatsApp বার্তা আসবে শুধু আমাদের নোটিফিকেশন নম্বর <strong>{{ $notificationsNumber }}</strong> থেকে।
            @if ($mainNumber) কথা বলতে আমাদের মূল নম্বরে কল বা WhatsApp করুন: <strong>{{ $mainNumber }}</strong>। @endif
          @endif
        @else
          {{ $locale === 'en' ? 'Questions? Call or WhatsApp our main line' : 'প্রশ্ন থাকলে মূল নম্বরে কল বা WhatsApp করুন:' }} <strong>{{ $mainNumber }}</strong>
        @endif
      </div>
    @endif
    <div style="padding:14px 22px;background:#F7F9FD;border-top:1px solid #E2E7F0;font-size:12.5px;color:#5B6B88;line-height:1.6">
      {{ $address }}<br>
      @if ($mainNumber){{ $mainNumber }}@endif @if ($email) · {{ $email }}@endif @if ($website) · {{ preg_replace('#^https?://#', '', rtrim($website, '/')) }}@endif
    </div>
  </div>
</body>
</html>
