<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = ['email', 'locale', 'status', 'unsubscribe_token', 'subscribed_at', 'unsubscribed_at'];

    protected $hidden = ['unsubscribe_token'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }
}
