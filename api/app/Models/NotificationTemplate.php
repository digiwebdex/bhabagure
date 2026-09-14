<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['event', 'channel', 'subject_bn', 'subject_en', 'body_bn', 'body_en', 'is_enabled', 'updated_by_staff_id'];

    protected function casts(): array
    {
        return ['event' => NotificationEvent::class, 'channel' => NotificationChannel::class, 'is_enabled' => 'boolean'];
    }

    public function body(string $locale): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_bn;
    }

    public function subject(string $locale): ?string
    {
        return $locale === 'en' ? $this->subject_en : $this->subject_bn;
    }
}
