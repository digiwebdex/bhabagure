<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor analytics (docs/visitor-analytics.md): the public website's visits, recorded without cookies and without
 * keeping anyone's IP address. A session is one browser tab's visit; its events are the pages viewed and the things
 * done. New tables only — nothing existing changes, and dropping these two removes the feature's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->id();
            // The tab's random id (sessionStorage), never a cookie and never shown in the admin.
            $table->char('token', 36)->unique();
            // A one-way code for "the same browser today": SHA-256 of a salt that changes every day, the IP and the
            // browser. The salt is thrown away after two days, so the code can't be traced back or matched across days.
            $table->char('visitor_hash', 64)->index();
            $table->dateTime('started_at')->index();
            $table->dateTime('last_seen_at')->index();
            $table->string('landing_path', 255);
            $table->string('last_path', 255);
            $table->char('locale', 2)->nullable();
            // direct · search · social · referral · campaign
            $table->string('source', 12);
            $table->string('referrer_domain', 120)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            // From Cloudflare, only on requests that really came through it; null where unknown.
            $table->char('country_code', 2)->nullable()->index();
            $table->char('continent', 2)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('timezone', 50)->nullable();
            // desktop · mobile · tablet
            $table->string('device', 10);
            $table->string('browser', 30);
            $table->string('os', 30);
            $table->unsignedInteger('page_views')->default(0);
            // Seconds the tab was visible, from heartbeats (at most one minute counted per beat).
            $table->unsignedInteger('engaged_seconds')->default(0);
            $table->timestamps();

            $table->index(['started_at', 'country_code']);
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('analytics_sessions')->cascadeOnDelete();
            // Sent with the event, so a retried request is stored once (and a conversion once per booking or enquiry).
            $table->char('event_key', 64)->unique();
            // page_view · package_view · booking_started · booking_completed · enquiry · air_quote · hotel_quote ·
            // newsletter · review · payment_request
            $table->string('type', 24);
            $table->string('path', 255);
            // What it was about: a package's slug, an enquiry's kind. Never a person's details.
            $table->string('subject', 120)->nullable();
            $table->dateTime('occurred_at');

            $table->index(['type', 'occurred_at']);
            $table->index(['occurred_at', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_sessions');
    }
};
