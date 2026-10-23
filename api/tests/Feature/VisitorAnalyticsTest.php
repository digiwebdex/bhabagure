<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Inquiry;
use App\Services\Analytics\AnalyticsRecorder;
use App\Services\Analytics\Paths;
use App\Services\Analytics\Referrers;
use App\Services\Analytics\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/visitor-analytics.md: visits recorded without cookies or IP addresses, located only by what Cloudflare really
 * sent, stored once however often a request is retried, conversions only from actions that succeeded, and reports
 * for the staff allowed to see them.
 */
class VisitorAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const DHAKA = ['CF-IPCountry' => 'BD', 'CF-IPContinent' => 'AS', 'CF-Region' => 'Dhaka Division', 'CF-IPCity' => 'Dhaka', 'CF-Timezone' => 'Asia/Dhaka', 'CF-IPLatitude' => '23.7', 'CF-IPLongitude' => '90.4'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Dhaka'));
    }

    #[Test]
    public function a_visit_is_located_only_by_what_cloudflare_really_sent_and_no_ip_is_kept(): void
    {
        $session = (string) Str::uuid();
        $this->beacon($session, [$this->pageView('/en/packages/nepal-mustang')], headers: self::DHAKA, referrer: 'https://www.google.com.bd/search?q=mustang', ip: '103.4.5.6')->assertAccepted();

        $visit = AnalyticsSession::query()->sole();
        // The English page counts as the page; the package is its subject.
        $this->assertSame(['/packages/nepal-mustang', 'en', 'search', 'google.com.bd'], [$visit->landing_path, $visit->locale, $visit->source, $visit->referrer_domain]);
        $this->assertSame(['BD', 'AS', 'Dhaka Division', 'Dhaka', 'Asia/Dhaka'], [$visit->country_code, $visit->continent, $visit->region, $visit->city, $visit->timezone]);
        $this->assertSame(['desktop', 'Chrome', 'Windows', 1], [$visit->device, $visit->browser, $visit->os, $visit->page_views]);
        $this->assertSame('package:nepal-mustang', AnalyticsEvent::query()->sole()->subject);
        // Nothing anywhere holds the address, the coordinates or the search words.
        $stored = json_encode([DB::table('analytics_sessions')->get(), DB::table('analytics_events')->get()]);
        foreach (['103.4.5.6', '23.7', '90.4', 'mustang&', 'q=mustang'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $stored);
        }

        // The same headers on a request that didn't come through Cloudflare (nginx says so): the place is unknown.
        $this->beacon((string) Str::uuid(), [$this->pageView('/')], headers: self::DHAKA, viaCloudflare: false)->assertAccepted();
        $direct = AnalyticsSession::query()->latest('id')->first();
        $this->assertNull($direct->country_code);
        $this->assertNull($direct->city);
        // Tor and "unknown" aren't countries.
        $this->beacon((string) Str::uuid(), [$this->pageView('/')], headers: ['CF-IPCountry' => 'T1'])->assertAccepted();
        $this->assertNull(AnalyticsSession::query()->latest('id')->first()->country_code);
    }

    #[Test]
    public function an_event_sent_twice_is_stored_once_and_bots_private_pages_and_conversions_from_the_browser_are_not(): void
    {
        $session = (string) Str::uuid();
        $view = $this->pageView('/');
        $this->beacon($session, [$view])->assertAccepted();
        $this->beacon($session, [$view])->assertAccepted();
        $this->assertSame([1, 1], [AnalyticsEvent::query()->count(), AnalyticsSession::query()->sole()->page_views]);

        // The heartbeat of a visible tab: at most a minute per beat.
        $this->beacon($session, [], heartbeat: 30)->assertAccepted();
        $this->beacon($session, [], heartbeat: 600)->assertAccepted();
        $this->assertSame(90, AnalyticsSession::query()->sole()->engaged_seconds);

        // A booking's page and an unsubscribe link keep their kind, never the reference or token; the portal isn't counted.
        $this->beacon($session, [$this->pageView('/booking/BH-2026-00042'), $this->pageView('/newsletter/unsubscribe/abcdef123'), $this->pageView('/portal/trips/BH-1'), $this->pageView('/en/portal')])->assertAccepted();
        $this->assertSame(['/', '/booking/:reference', '/newsletter/unsubscribe/:token'], AnalyticsEvent::query()->orderBy('id')->pluck('path')->all());

        // A crawler, an empty user agent, a script: not counted.
        foreach (['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '', 'curl/8.4.0', 'facebookexternalhit/1.1'] as $bot) {
            $this->beacon((string) Str::uuid(), [$this->pageView('/')], agent: $bot)->assertAccepted();
        }
        $this->assertSame(1, AnalyticsSession::query()->count());

        // A conversion only ever comes from the API, when the action succeeded.
        $this->beacon($session, [['key' => (string) Str::uuid(), 'type' => 'booking_completed', 'path' => '/']])->assertUnprocessable();
    }

    #[Test]
    public function the_beacon_refuses_what_the_website_never_sends_and_is_rate_limited(): void
    {
        $this->beacon('not-a-uuid', [$this->pageView('/')])->assertUnprocessable();
        $this->beacon((string) Str::uuid(), array_map(fn () => $this->pageView('/'), range(1, 11)))->assertUnprocessable();
        $this->withHeaders(['User-Agent' => self::CHROME])->call('POST', '/api/v1/public/analytics/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['session' => (string) Str::uuid(), 'events' => [$this->pageView('/'.str_repeat('a', 9000))]]))
            ->assertStatus(413);

        $session = (string) Str::uuid();
        for ($i = 0; $i < 60; $i++) {
            $this->beacon($session, [], heartbeat: 1, ip: '198.51.100.9')->assertAccepted();
        }
        $this->beacon($session, [], heartbeat: 1, ip: '198.51.100.9')->assertTooManyRequests();
    }

    #[Test]
    public function conversions_come_from_actions_that_succeeded_once_each_on_the_visit_that_made_them(): void
    {
        $session = (string) Str::uuid();
        $this->beacon($session, [$this->pageView('/')])->assertAccepted();

        // An enquiry from the website, with the visit's id: recorded on that visit.
        $this->withHeaders([AnalyticsRecorder::SESSION_HEADER => $session])->postJson('/api/v1/public/inquiries', ['name' => 'Rafiq Islam', 'phone' => '01911223344', 'message' => 'Bali in December?'])->assertAccepted();
        $event = AnalyticsEvent::query()->where('type', 'enquiry')->sole();
        $this->assertSame([AnalyticsSession::query()->sole()->id, 'contact'], [$event->session_id, $event->subject]);

        // The same enquiry recorded again (a retry): once.
        $inquiry = Inquiry::query()->sole();
        $request = request()->duplicate();
        $request->headers->set(AnalyticsRecorder::SESSION_HEADER, $session);
        app(AnalyticsRecorder::class)->conversion($request, 'enquiry', 'inquiry:'.$inquiry->id);
        $this->assertSame(1, AnalyticsEvent::query()->where('type', 'enquiry')->count());

        // Without a known visit, or turned away by the honeypot: nothing.
        $this->flushHeaders();
        $this->postJson('/api/v1/public/inquiries', ['name' => 'Another', 'phone' => '01911223355'])->assertAccepted();
        $this->withHeaders([AnalyticsRecorder::SESSION_HEADER => (string) Str::uuid()])->postJson('/api/v1/public/air-quotes', [
            'from_place' => 'Dhaka', 'to_place' => 'Kathmandu', 'depart_on' => '2026-11-12', 'passengers' => 2, 'cabin_class' => 'economy', 'name' => 'Nobody', 'phone' => '01911223366',
        ])->assertAccepted();
        $this->withHeaders([AnalyticsRecorder::SESSION_HEADER => $session])->postJson('/api/v1/public/inquiries', ['name' => 'Bot', 'phone' => '01911223377', 'company' => 'spam'])->assertAccepted();
        $this->assertSame(1, AnalyticsEvent::query()->whereIn('type', AnalyticsEvent::CONVERSIONS)->count());
    }

    #[Test]
    public function the_reports_count_sessions_visitors_and_pages_by_country_source_and_device_with_filters(): void
    {
        // Two visits from Dhaka on the 18th (one on an iPhone, from Facebook), one from London on the 19th, and one
        // with no known country on the 20th; and a visit last month for the previous-period comparison.
        $this->visitOn('2026-10-18 11:00', ['/', '/packages/nepal-mustang'], self::DHAKA, referrer: 'https://www.google.com/');
        $this->visitOn('2026-10-18 12:00', ['/packages/nepal-mustang'], self::DHAKA, agent: self::IPHONE, referrer: 'https://l.facebook.com/');
        $this->visitOn('2026-10-19 09:00', ['/', '/visa/thailand', '/'], ['CF-IPCountry' => 'GB', 'CF-IPCity' => 'London', 'CF-Region' => 'England']);
        $this->visitOn('2026-10-20 08:00', ['/blog/bali'], [], utm: ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'october']);
        $this->visitOn('2026-09-25 10:00', ['/'], self::DHAKA);

        $admin = $this->staff('admin');
        $summary = $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2026-10-18&to=2026-10-20')->assertOk()->json('data');
        $this->assertSame(4, $summary['totals']['sessions']);
        $this->assertSame(7, $summary['totals']['pageViews']);
        // JSON writes 50.0 as 50: compared by value.
        $this->assertEquals(1.75, $summary['totals']['viewsPerSession']);
        $this->assertSame(2, $summary['totals']['countries']);
        $this->assertEquals(50, $summary['totals']['bounceRate']);
        $this->assertEquals(75, $summary['countryCoverage']);
        $this->assertSame([2, 1, 1], array_column($summary['series'], 'sessions'));
        // The three days before: the September visit isn't in them (it's on the 25th).
        $this->assertSame(0, $summary['previous']['sessions']);

        $countries = $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/country?from=2026-10-18&to=2026-10-20')->json('data');
        $this->assertEquals([['Bangladesh', 2, 50], ['United Kingdom', 1, 25], ['Unknown', 1, 25]], array_map(fn ($row) => [$row['label'], $row['sessions'], $row['share']], $countries['rows']));
        $cities = $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/city?from=2026-10-18&to=2026-10-20')->json('data.rows');
        $this->assertSame('Dhaka, Dhaka Division, Bangladesh', $cities[0]['label']);
        $sources = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/source?from=2026-10-18&to=2026-10-20')->json('data.rows'))->pluck('sessions', 'label')->all();
        $this->assertSame(['direct' => 1, 'search' => 1, 'social' => 1, 'campaign' => 1], array_merge(['direct' => 0, 'search' => 0, 'social' => 0, 'campaign' => 0], $sources));
        $this->assertSame('newsletter / email / october', $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/campaign?from=2026-10-18&to=2026-10-20')->json('data.rows.0.label'));

        $pages = $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/page?from=2026-10-18&to=2026-10-20')->json('data.rows');
        $this->assertSame(['/', 3, 2], [$pages[0]['label'], $pages[0]['events'], $pages[0]['sessions']]);
        $tours = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/tour?from=2026-10-18&to=2026-10-20')->json('data.rows'))->pluck('views', 'label')->all();
        $this->assertSame(['package:nepal-mustang' => 2, 'visa:thailand' => 1], $tours);
        // The last page of each visit.
        $this->assertSame(['/packages/nepal-mustang' => 2, '/' => 1, '/blog/bali' => 1], collect($this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/exit?from=2026-10-18&to=2026-10-20')->json('data.rows'))->pluck('sessions', 'label')->all());

        // Filters: one country, one device, unknown country, one page.
        $this->assertSame(2, $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2026-10-18&to=2026-10-20&country=BD')->json('data.totals.sessions'));
        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2026-10-18&to=2026-10-20&device=mobile')->json('data.totals.sessions'));
        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2026-10-18&to=2026-10-20&country=unknown')->json('data.totals.sessions'));
        $this->assertSame(2, $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2026-10-18&to=2026-10-20&path=/packages/nepal-mustang')->json('data.totals.sessions'));

        // A bounded period, and only known reports.
        $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?from=2025-01-01&to=2026-10-20')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary?country=bd;drop')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/breakdown/password')->assertNotFound();
    }

    #[Test]
    public function live_means_seen_in_the_last_minutes_and_a_csv_follows_the_filters(): void
    {
        $this->visitOn('2026-10-20 09:57', ['/', '/packages/nepal-mustang'], self::DHAKA);
        $this->visitOn('2026-10-20 09:40', ['/'], ['CF-IPCountry' => 'IN']);
        $admin = $this->staff('admin');

        $live = $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/live')->assertOk()->json('data');
        $this->assertSame(5, $live['activeMinutes']);
        $this->assertCount(1, $live['sessions']);
        $this->assertSame(['BD', 'Dhaka', '/packages/nepal-mustang', '/'], [$live['sessions'][0]['country'], $live['sessions'][0]['city'], $live['sessions'][0]['page'], $live['sessions'][0]['landing']]);
        $this->assertArrayNotHasKey('token', $live['sessions'][0]);
        $this->assertCount(3, $live['recent']);

        $csv = $this->actingAsApi($admin)->get('/api/v1/admin/analytics/export/country?from=2026-10-20&to=2026-10-20')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}Label,Sessions,Visitors,\"Page views\",\"Converting sessions\",Share", $body);
        $this->assertStringContainsString('Bangladesh,1,1,2,0,50', $body);
        $this->assertStringContainsString('India,1,1,1,0,50', $body);
        // Both visits came from one browser on one address that day: one visitor, two sessions.
        $this->assertStringContainsString('2026-10-20,1,2,3', $this->actingAsApi($admin)->get('/api/v1/admin/analytics/export/daily?from=2026-10-20&to=2026-10-20')->streamedContent());
    }

    #[Test]
    public function reports_need_analytics_view_a_csv_analytics_export_and_the_settings_the_super_admin(): void
    {
        // Signed out first: a sign-in lasts for the rest of a test.
        $this->getJson('/api/v1/admin/analytics/summary')->assertUnauthorized();
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/analytics/summary')->assertForbidden();
        $this->actingAsApi($this->staff('accountant'))->get('/api/v1/admin/analytics/export/daily')->assertForbidden();
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/summary')->assertOk();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/analytics/settings')->assertForbidden();

        $owner = $this->staff('super_admin');
        $this->actingAsApi($owner)->getJson('/api/v1/admin/analytics/settings')->assertOk()->assertJsonPath('data.retentionMonths', 13);
        $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', ['enabled' => true, 'retentionMonths' => 99, 'activeMinutes' => 5, 'sessionMinutes' => 30, 'excludedPaths' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('retentionMonths');
        $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', ['enabled' => true, 'retentionMonths' => 6, 'activeMinutes' => 3, 'sessionMinutes' => 30, 'excludedPaths' => ['/blog']])->assertOk();

        // An excluded path isn't counted; switched off, nothing is.
        $this->beacon((string) Str::uuid(), [$this->pageView('/blog/bali'), $this->pageView('/en/blog')])->assertAccepted();
        $this->assertSame(0, AnalyticsSession::query()->count());
        $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', ['enabled' => false, 'retentionMonths' => 6, 'activeMinutes' => 3, 'sessionMinutes' => 30, 'excludedPaths' => []])->assertOk();
        $this->beacon((string) Str::uuid(), [$this->pageView('/')])->assertAccepted();
        $this->assertSame(0, AnalyticsSession::query()->count());
    }

    #[Test]
    public function the_super_admin_connects_google_analytics_and_the_website_gets_its_measurement_id(): void
    {
        $this->getJson('/api/v1/public/settings')->assertOk()->assertJsonPath('data.ga4', null);
        $owner = $this->staff('super_admin');
        $settings = ['enabled' => true, 'retentionMonths' => 13, 'activeMinutes' => 5, 'sessionMinutes' => 30, 'excludedPaths' => []];

        // An old Universal Analytics id, or anything else, isn't a GA4 Measurement ID.
        foreach (['UA-12345-1', 'G-', 'G-ABC 123', '<script>'] as $wrong) {
            $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', $settings + ['ga4MeasurementId' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('ga4MeasurementId');
        }
        // Admins see the reports but don't connect Google.
        $this->actingAsApi($this->staff('admin'))->putJson('/api/v1/admin/analytics/settings', $settings + ['ga4MeasurementId' => 'G-ABC123XYZ'])->assertForbidden();

        $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', $settings + ['ga4MeasurementId' => ' g-abc123xyz '])->assertOk()->assertJsonPath('data.ga4MeasurementId', 'G-ABC123XYZ');
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.ga4', 'G-ABC123XYZ');

        // Emptied: disconnected.
        $this->actingAsApi($owner)->putJson('/api/v1/admin/analytics/settings', $settings + ['ga4MeasurementId' => null])->assertOk()->assertJsonPath('data.ga4MeasurementId', null);
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.ga4', null);
    }

    #[Test]
    public function visits_older_than_the_retention_period_are_deleted_with_their_events(): void
    {
        $this->visitOn('2025-08-01 10:00', ['/', '/visa/thailand'], self::DHAKA);
        $this->visitOn('2026-01-10 10:00', ['/'], self::DHAKA);

        $this->artisan('analytics:prune')->assertSuccessful();
        $this->assertSame(['2026-01-10'], AnalyticsSession::query()->get()->map(fn ($s) => $s->started_at->timezone('Asia/Dhaka')->toDateString())->all());
        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    #[Test]
    public function browsers_referrers_and_paths_are_read_in_broad_families_only(): void
    {
        $this->assertSame(['mobile', 'Safari', 'iOS'], array_values(array_slice(UserAgent::classify(self::IPHONE), 1)));
        $this->assertSame('tablet', UserAgent::classify('Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36')['device']);
        $this->assertSame('Facebook app', UserAgent::classify('Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/480.0.0.40.94;]')['browser']);
        $this->assertTrue(UserAgent::classify('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0 Safari/537.36')['bot']);

        $this->assertSame(['search', 'google.co.uk'], array_values(Referrers::classify('https://www.google.co.uk/', [])));
        $this->assertSame(['social', 'm.facebook.com'], array_values(Referrers::classify('https://m.facebook.com/story', [])));
        $this->assertSame(['direct', null], array_values(Referrers::classify('https://bhabaghure.com.bd/packages', [])));
        $this->assertSame(['referral', 'tripadvisor.com'], array_values(Referrers::classify('https://www.tripadvisor.com/x', [])));
        $this->assertSame('campaign', Referrers::classify(null, ['source' => 'fb_ads'])['source']);
        // A look-alike isn't Google.
        $this->assertSame('referral', Referrers::classify('https://notgoogle.example/', [])['source']);

        $this->assertNull(Paths::normalize('//evil.example/x'));
        $this->assertNull(Paths::normalize('javascript:alert(1)'));
        $this->assertSame('/packages/x', Paths::normalize('/en/packages/x?utm_source=a#top'));
    }

    /** @param  array<string, string>  $headers */
    private function visitOn(string $at, array $paths, array $headers, string $agent = self::CHROME, ?string $referrer = null, ?array $utm = null): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'Asia/Dhaka'));
        $session = (string) Str::uuid();
        foreach ($paths as $path) {
            $this->beacon($session, [$this->pageView($path)], headers: $headers, agent: $agent, referrer: $referrer, utm: $utm)->assertAccepted();
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Dhaka'));
    }

    /** @return array{key: string, type: string, path: string} */
    private function pageView(string $path): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'page_view', 'path' => $path];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, string>  $headers
     */
    private function beacon(string $session, array $events, array $headers = [], string $agent = self::CHROME, ?string $referrer = null, ?array $utm = null, ?int $heartbeat = null, bool $viaCloudflare = true, string $ip = '203.0.113.7'): TestResponse
    {
        // Each visit's own headers only: test headers otherwise carry on to the next request.
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip] + ($viaCloudflare ? ['BH_FROM_CLOUDFLARE' => '1'] : []))
            ->withHeaders(['User-Agent' => $agent] + $headers)
            ->postJson('/api/v1/public/analytics/events', array_filter(['session' => $session, 'events' => $events, 'referrer' => $referrer, 'utm' => $utm, 'heartbeat' => $heartbeat, 'locale' => 'en'], fn ($value) => $value !== null));
    }
}
