{!! $senderLine !!}

{!! implode("\n", $lines) !!}
@if ($toCustomer && $notificationsNumber)

{!! $locale === 'en' ? "Automated WhatsApp messages about your booking come only from our notifications number {$notificationsNumber}." : "আপনার বুকিং নিয়ে স্বয়ংক্রিয় WhatsApp বার্তা আসবে শুধু আমাদের নোটিফিকেশন নম্বর {$notificationsNumber} থেকে।" !!}
@endif
@if ($mainNumber)
{!! $locale === 'en' ? "Main line: {$mainNumber}" : "মূল নম্বর: {$mainNumber}" !!}
@endif
{!! $address !!}
