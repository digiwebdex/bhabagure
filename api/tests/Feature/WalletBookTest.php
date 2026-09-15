<?php

namespace Tests\Feature;

use App\Exceptions\LedgerImmutable;
use App\Wallet\Models\Source;
use App\Wallet\Models\Transaction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8: cash in and out with a source, a reference and evidence; deals with an
 * advance no more than the total and payments no more than what is due; reversal with a reason instead of delete;
 * evidence encrypted with the wallet's own key.
 */
class WalletBookTest extends TestCase
{
    use UsesWalletDatabase;

    protected $connectionsToTransact = [null, 'wallet'];

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Dhaka')->setDate(2026, 9, 16)->setTime(11, 0));
        $this->session = $this->walletSignIn($this->staff('super_admin')->email);
    }

    #[Test]
    public function cash_in_and_out_move_the_balance_by_source_and_a_reversal_takes_an_entry_back_with_its_reason(): void
    {
        Storage::fake('local');
        $business = $this->wallet('POST', 'sources', ['name' => 'Family business'], $this->session)->assertCreated()->json('data');
        $businessId = collect($business)->firstWhere('name', 'Family business')['id'];
        $personal = Source::query()->where('name', 'like', '%Personal')->value('id');
        $this->wallet('POST', 'sources', ['name' => 'Family business'], $this->session)->assertConflict()->assertJsonPath('code', 'source_exists');

        // A reference is required; evidence is optional and kept encrypted with WALLET_KEY.
        $this->wallet('POST', 'transactions', ['direction' => 'in', 'amount' => 120000, 'source_id' => $businessId], $this->session)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $in = $this->walletMultipart('transactions', ['direction' => 'in', 'amount' => '120000', 'source_id' => (string) $businessId, 'reference' => 'August profit share', 'occurred_on' => '2026-09-10'],
            UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'))->assertCreated()->assertJsonPath('data.has_evidence', true);
        $out = $this->wallet('POST', 'transactions', ['direction' => 'out', 'amount' => 45000, 'source_id' => $personal, 'reference' => 'Family expense'], $this->session)->assertCreated();
        $this->wallet('POST', 'transactions', ['direction' => 'out', 'amount' => 5, 'source_id' => 999, 'reference' => 'Nowhere'], $this->session)->assertUnprocessable()->assertJsonPath('code', 'source_unknown');

        $summary = $this->wallet('GET', 'summary', session: $this->session)->assertOk();
        $this->assertSame([75000, 120000, 45000, 120000], [(int) $summary->json('data.balance'), (int) $summary->json('data.total_in'), (int) $summary->json('data.total_out'), (int) $summary->json('data.month_in')]);
        $this->assertSame(['Family business', 120000, 0], [$summary->json('data.by_source.0.name'), (int) $summary->json('data.by_source.0.in'), (int) $summary->json('data.by_source.0.out')]);

        // Evidence: stored encrypted, not with APP_KEY, and served back as it was uploaded.
        $row = Transaction::query()->findOrFail($in->json('data.id'));
        $raw = Storage::disk('local')->get($row->evidence_path);
        $this->assertStringStartsWith('wallet/evidence/', $row->evidence_path);
        try {
            decrypt($raw, false);
            $this->fail('Wallet evidence opened with APP_KEY.');
        } catch (DecryptException) {
            $this->addToAssertionCount(1);
        }
        $this->withUnencryptedCookie('bh_wallet', $this->session)->get("/api/v1/wallet/transactions/{$row->id}/evidence", ['X-Wallet-Request' => '1'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');

        // Reversing the cash out: an entry the other way, with the reason; once only; a reversal can't be reversed.
        $reversal = $this->wallet('POST', "transactions/{$out->json('data.id')}/reverse", ['reason' => 'Entered twice'], $this->session)->assertCreated()
            ->assertJsonPath('data.direction', 'in')->assertJsonPath('data.reason', 'Entered twice');
        $this->wallet('POST', "transactions/{$out->json('data.id')}/reverse", ['reason' => 'Again'], $this->session)->assertConflict()->assertJsonPath('code', 'already_reversed');
        $this->wallet('POST', "transactions/{$reversal->json('data.id')}/reverse", ['reason' => 'Undo'], $this->session)->assertConflict()->assertJsonPath('code', 'not_reversible');
        $this->assertSame(120000, (int) $this->wallet('GET', 'summary', session: $this->session)->json('data.balance'));
        $this->wallet('GET', 'transactions?direction=out', session: $this->session)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reversed', true);

        // Presets per direction.
        $this->wallet('POST', 'presets', ['direction' => 'in', 'label' => 'Profit share'], $this->session)->assertCreated()->assertJsonPath('data.0.label', 'Profit share');
        $preset = $this->wallet('POST', 'presets', ['direction' => 'out', 'label' => 'Zakat'], $this->session)->json('data.1.id');
        $this->wallet('POST', "presets/{$preset}/remove", session: $this->session)->assertOk()->assertJsonCount(1, 'data');

        $this->expectException(LedgerImmutable::class);
        $row->update(['amount' => 1]);
    }

    #[Test]
    public function a_deal_takes_an_advance_no_more_than_its_total_then_payments_no_more_than_what_is_due(): void
    {
        $this->wallet('POST', 'deals', ['name' => 'Software project', 'total' => 80000, 'advance' => 90000], $this->session)->assertUnprocessable()->assertJsonPath('code', 'advance_over_total');
        $deal = $this->wallet('POST', 'deals', ['name' => 'Software project', 'total' => 80000, 'advance' => 40000, 'note' => 'Website and booking page'], $this->session)->assertCreated()
            ->assertJsonPath('data.received', 40000)->assertJsonPath('data.due', 40000)->assertJsonPath('data.payments.0.kind', 'deal_advance');
        $id = $deal->json('data.id');

        $this->wallet('POST', "deals/{$id}/payments", ['amount' => 50000], $this->session)->assertUnprocessable()->assertJsonPath('code', 'payment_over_due');
        $paid = $this->wallet('POST', "deals/{$id}/payments", ['amount' => 40000], $this->session)->assertCreated()
            ->assertJsonPath('data.due', 0)->assertJsonPath('data.settled', true)->assertJsonPath('data.payments.1.reference', 'বাকি পরিশোধ');

        // The advance and payment are cash in under the deal's name; reversing a payment makes it due again.
        $summary = $this->wallet('GET', 'summary', session: $this->session);
        $this->assertSame(['Software project', 80000, 0], [$summary->json('data.by_source.0.name'), (int) $summary->json('data.by_source.0.in'), (int) $summary->json('data.due_total')]);
        $this->wallet('POST', "transactions/{$paid->json('data.payments.1.id')}/reverse", ['reason' => 'Cheque bounced'], $this->session)->assertCreated();
        $this->wallet('GET', 'deals', session: $this->session)->assertJsonPath('data.0.due', 40000)->assertJsonPath('data.0.settled', false)->assertJsonPath('data.0.payments.1.reversed', true);
        $this->assertSame(40000, (int) $this->wallet('GET', 'summary', session: $this->session)->json('data.balance'));
    }

    /** @param array<string, string> $fields */
    private function walletMultipart(string $uri, array $fields, UploadedFile $file): TestResponse
    {
        $this->resetAuthState();

        return $this->withUnencryptedCookie('bh_wallet', $this->session)
            ->post("/api/v1/wallet/{$uri}", $fields + ['evidence' => $file], ['X-Wallet-Request' => '1', 'Accept' => 'application/json']);
    }
}
