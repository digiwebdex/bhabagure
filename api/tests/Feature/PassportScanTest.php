<?php

namespace Tests\Feature;

use App\Models\BookingTraveller;
use App\Models\PassportScan;
use App\Services\Passports\MrzParser;
use App\Services\Passports\PassportTextReader;
use App\Services\Passports\TextractPassportTextReader;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\Textract\Exception\TextractException;
use Aws\Textract\TextractClient;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/phase-3-booking.md §5: OCR helps, never blocks, and never accepts a field whose check digit fails. */
class PassportScanTest extends TestCase
{
    use RefreshDatabase;

    private const MRZ = "P<BGDHASAN<<TANVIR<<<<<<<<<<<<<<<<<<<<<<<<<<\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[Test]
    public function without_a_provider_the_scan_is_kept_encrypted_and_the_traveller_types_the_fields(): void
    {
        $upload = UploadedFile::fake()->createWithContent('passport.jpg', $this->jpeg());

        $data = $this->post('/api/v1/public/passport-scans', ['file' => $upload], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.fields', null)->json('data');

        $this->assertSame(48, strlen($data['token']));
        $scan = PassportScan::query()->sole();
        $stored = Storage::disk('local')->get($scan->path);
        $this->assertStringNotContainsString($this->jpeg(), $stored, 'never stored in the clear');
        $this->assertSame($this->jpeg(), Crypt::decryptString($stored));
        $this->assertStringStartsWith('passport-scans/', $scan->path);
    }

    #[Test]
    public function a_clean_mrz_fills_the_fields_and_a_failed_check_digit_asks_to_confirm(): void
    {
        $line2 = $this->line2('A01234567', '900412', '300131');
        $this->readerReturns("PASSPORT\n".self::MRZ.$line2);
        $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->createWithContent('p.jpg', $this->jpeg())], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'read')
            ->assertJsonPath('data.fields.fullName', 'Tanvir Hasan')
            ->assertJsonPath('data.fields.passportNumber', ['value' => 'A01234567', 'confirm' => false])
            ->assertJsonPath('data.fields.passportExpiry', ['value' => '2030-01-31', 'confirm' => false]);

        // OCR misread one digit of the expiry date: returned, but flagged for the traveller to check.
        $this->readerReturns(self::MRZ.substr_replace($line2, '8', 22, 1));
        $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->createWithContent('p.jpg', $this->jpeg())], ['Accept' => 'application/json'])
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.fields.passportExpiry.confirm', true)
            ->assertJsonPath('data.fields.passportNumber.confirm', false);
    }

    #[Test]
    public function a_textract_error_degrades_to_unavailable(): void
    {
        $mock = new MockHandler;
        $mock->append(new TextractException('Throttled', new Command('DetectDocumentText'), ['code' => 'ProvisionedThroughputExceededException']));
        $mock->append(new Result(['Blocks' => [
            ['BlockType' => 'PAGE'],
            ['BlockType' => 'LINE', 'Text' => 'P<BGDHASAN<<TANVIR<<<<<<<<<<<<<<<<<<<<<<<<<<'],
            ['BlockType' => 'WORD', 'Text' => 'ignored'],
        ]]));
        $reader = new TextractPassportTextReader(new TextractClient([
            'version' => '2018-06-27', 'region' => 'ap-south-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'retries' => 0, 'handler' => $mock,
        ]));

        $this->assertNull($reader->read('bytes', 'image/jpeg'));
        $this->assertSame('P<BGDHASAN<<TANVIR<<<<<<<<<<<<<<<<<<<<<<<<<<', $reader->read('bytes', 'image/jpeg'));
    }

    #[Test]
    public function files_other_than_images_and_pdfs_or_over_5_mb_are_refused(): void
    {
        $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->create('passport.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->create('passport.jpg', 6000, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, PassportScan::query()->count());
    }

    #[Test]
    public function a_booking_takes_the_scan_once_and_unused_scans_are_pruned(): void
    {
        $this->seed(ContentSeeder::class);
        $used = $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->createWithContent('a.jpg', $this->jpeg())], ['Accept' => 'application/json'])->json('data.token');
        $this->post('/api/v1/public/passport-scans', ['file' => UploadedFile::fake()->createWithContent('b.jpg', $this->jpeg())], ['Accept' => 'application/json']);

        $this->postJson('/api/v1/public/bookings', [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => '2026-10-31', 'pax' => 1, 'room' => 'twin',
            'travellers' => [['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2030-01-31', 'phone' => '01711000001', 'passport_scan_token' => $used, 'ocr_filled' => true]],
            'expected_total' => 76500, 'terms_accepted' => true, 'locale' => 'bn',
        ])->assertCreated();

        $traveller = BookingTraveller::query()->sole();
        $this->assertNotNull($traveller->passport_scan_path);
        $this->assertNotNull($traveller->ocr_filled_at);
        $this->assertSame($traveller->id, PassportScan::query()->where('token_hash', PassportScan::hashToken($used))->value('booking_traveller_id'));

        $this->travel(25)->hours();
        $this->artisan('passport-scans:prune')->assertSuccessful();
        $this->assertSame(1, PassportScan::query()->count(), 'the unused scan is gone, the booked one stays');
        Storage::disk('local')->assertExists($traveller->passport_scan_path);
    }

    private function readerReturns(string $text): void
    {
        $this->app->instance(PassportTextReader::class, new class($text) implements PassportTextReader
        {
            public function __construct(private readonly string $text) {}

            public function name(): string
            {
                return 'stub';
            }

            public function read(string $bytes, string $mime): ?string
            {
                return $this->text;
            }
        });
    }

    private function line2(string $number, string $dob, string $expiry): string
    {
        $check = fn (string $v) => MrzParser::checkDigit($v);
        $body = $number.$check($number).'BGD'.$dob.$check($dob).'M'.$expiry.$check($expiry).'<<<<<<<<<<<<<<'.'0';

        return $body.$check($number.$check($number).$dob.$check($dob).$expiry.$check($expiry).'<<<<<<<<<<<<<<0');
    }

    private function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }
}
