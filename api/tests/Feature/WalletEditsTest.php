<?php

namespace Tests\Feature;

use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Source;
use App\Wallet\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/wallet-edits.md: View, Edit and Delete on every entry, Edit, Add payment and Delete on deals, and rename and
 * delete for sources and saved references — with the ledger still append-only (an edit is a reversal plus the corrected
 * entry; a delete is the reversal) and "Show edits and deletions" showing what was there before.
 */
class WalletEditsTest extends TestCase
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
    public function an_edit_cancels_the_entry_on_its_own_date_and_records_the_corrected_one_in_its_place(): void
    {
        Storage::fake('local');
        $sources = $this->wallet('POST', 'sources', ['name' => 'Family business'], $this->session)->assertCreated()->json('data');
        $business = collect($sources)->firstWhere('name', 'Family business')['id'];
        $personal = collect($sources)->firstWhere('name', 'Personal')['id'];
        $original = $this->multipart('transactions', ['direction' => 'in', 'amount' => '120000', 'source_id' => (string) $business, 'reference' => 'August profit share', 'occurred_on' => '2026-09-10'],
            UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'))->assertCreated()->json('data');

        // Amount, source, reference and date changed, with a reason; the evidence is kept.
        $corrected = $this->wallet('POST', "transactions/{$original['id']}/edit", [
            'amount' => 100000, 'source_id' => $personal, 'reference' => 'August profit', 'occurred_on' => '2026-09-11', 'reason' => 'Typed wrong',
        ], $this->session)->assertOk()
            ->assertJsonPath('data.amount', 100000)->assertJsonPath('data.source', 'Personal')->assertJsonPath('data.status', 'standing')
            ->assertJsonPath('data.replaces_id', $original['id'])->assertJsonPath('data.has_evidence', true)->json('data');
        $this->assertSame(['amount', 'source', 'reference', 'occurred_on'], array_column($corrected['changes'], 'field'));
        $this->assertSame([120000, 100000], [(int) $corrected['changes'][0]['before'], (int) $corrected['changes'][0]['after']]);
        $this->assertSame(['Family business', 'Personal'], [$corrected['changes'][1]['before'], $corrected['changes'][1]['after']]);

        // The old row is untouched; a reversal on its own date with the reason cancels it.
        $this->assertSame('120000.00', Transaction::query()->findOrFail($original['id'])->amount);
        $reversal = Transaction::query()->where('reverses_id', $original['id'])->sole();
        $this->assertSame(['out', '2026-09-10', 'Typed wrong'], [$reversal->direction, $reversal->occurred_on->toDateString(), $reversal->reason]);
        $summary = $this->wallet('GET', 'summary', session: $this->session)->json('data');
        $this->assertSame([100000, 1, 'Personal'], [(int) $summary['balance'], $summary['count_in'], $summary['by_source'][0]['name']]);

        // The list shows the book as it stands; with the history, the old version too, marked edited.
        $this->wallet('GET', 'transactions', session: $this->session)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $corrected['id']);
        $history = $this->wallet('GET', 'transactions?history=1', session: $this->session)->assertJsonPath('meta.total', 2)->json('data');
        $old = collect($history)->firstWhere('id', $original['id']);
        $this->assertSame(['edited', 'Typed wrong', $corrected['id']], [$old['status'], $old['change_reason'], $old['replaced_by_id']]);

        // An entry already edited can't be changed again; the same values again change nothing.
        $this->wallet('POST', "transactions/{$original['id']}/edit", ['amount' => 1, 'reference' => 'Again', 'occurred_on' => '2026-09-10'], $this->session)
            ->assertConflict()->assertJsonPath('code', 'already_changed');
        $this->wallet('POST', "transactions/{$original['id']}/delete", session: $this->session)->assertConflict()->assertJsonPath('code', 'already_changed');
        $this->wallet('POST', "transactions/{$corrected['id']}/edit", ['amount' => 100000, 'source_id' => $personal, 'reference' => 'August profit', 'occurred_on' => '2026-09-11'], $this->session)
            ->assertUnprocessable()->assertJsonPath('code', 'nothing_changed');
        $this->wallet('POST', "transactions/{$corrected['id']}/edit", ['amount' => 100000, 'reference' => 'Tomorrow', 'occurred_on' => '2026-09-17'], $this->session)
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_on');

        // Edited again: cash out now, the evidence removed. The old file stays with the old version.
        $second = $this->wallet('POST', "transactions/{$corrected['id']}/edit", [
            'direction' => 'out', 'amount' => 100000, 'source_id' => $personal, 'reference' => 'August profit', 'occurred_on' => '2026-09-11', 'remove_evidence' => true,
        ], $this->session)->assertOk()->assertJsonPath('data.direction', 'out')->assertJsonPath('data.has_evidence', false)->json('data');
        $this->assertSame(['direction', 'evidence'], array_column($second['changes'], 'field'));
        $this->assertSame(-100000, (int) $this->wallet('GET', 'summary', session: $this->session)->json('data.balance'));
        $this->withUnencryptedCookie('bh_wallet', $this->session)->get("/api/v1/wallet/transactions/{$original['id']}/evidence", ['X-Wallet-Request' => '1'])->assertOk();

        // A new file attached; the source can stay one deleted from the list since, but can't move to another such.
        $third = $this->multipart("transactions/{$second['id']}/edit", ['direction' => 'out', 'amount' => '90000', 'source_id' => (string) $personal, 'reference' => 'August profit', 'occurred_on' => '2026-09-11'],
            UploadedFile::fake()->create('receipt.png', 10, 'image/png'))->assertOk()->assertJsonPath('data.has_evidence', true)->assertJsonPath('data.evidence_name', 'receipt.png')->json('data');
        $this->wallet('POST', "sources/{$business}/archive", session: $this->session)->assertOk();
        $this->wallet('POST', "transactions/{$third['id']}/edit", ['direction' => 'out', 'amount' => 90000, 'source_id' => $business, 'reference' => 'August profit', 'occurred_on' => '2026-09-11'], $this->session)
            ->assertUnprocessable()->assertJsonPath('code', 'source_unknown');
        $this->wallet('POST', "sources/{$personal}/archive", session: $this->session)->assertOk();
        $fourth = $this->wallet('POST', "transactions/{$third['id']}/edit", ['direction' => 'out', 'amount' => 80000, 'source_id' => $personal, 'reference' => 'August profit', 'occurred_on' => '2026-09-11'], $this->session)
            ->assertOk()->assertJsonPath('data.source', 'Personal')->json('data');

        // View: every version, oldest first, with what each edit changed.
        $view = $this->wallet('GET', "transactions/{$fourth['id']}", session: $this->session)->assertOk()->json('data');
        $this->assertSame([$original['id'], $corrected['id'], $second['id'], $third['id'], $fourth['id']], array_column($view['versions'], 'id'));
        $this->assertSame(['edited', 'edited', 'edited', 'edited', 'standing'], array_column($view['versions'], 'status'));
        $this->wallet('GET', "transactions/{$reversal->id}", session: $this->session)->assertNotFound();

        // Deleted, without a reason: gone from the balance and the list, kept in the history.
        $this->wallet('POST', "transactions/{$fourth['id']}/delete", session: $this->session)->assertOk();
        $this->assertSame(0, (int) $this->wallet('GET', 'summary', session: $this->session)->json('data.balance'));
        $this->wallet('GET', 'transactions', session: $this->session)->assertJsonPath('meta.total', 0);
        $this->wallet('GET', "transactions/{$fourth['id']}", session: $this->session)->assertJsonPath('data.status', 'deleted')->assertJsonPath('data.change_reason', null);
        $this->assertSame(4, AuditLog::query()->where('action', 'entry_edited')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'entry_deleted')->count());
    }

    #[Test]
    public function a_deal_is_edited_paid_and_deleted_with_its_money(): void
    {
        $deal = $this->wallet('POST', 'deals', ['name' => 'Software project', 'total' => 80000, 'advance' => 30000, 'note' => 'Website'], $this->session)->assertCreated()->json('data');
        $advance = $deal['payments'][0]['id'];

        // Edit: name, total and note; the total can't drop below what was received, and the same values change nothing.
        $this->wallet('POST', "deals/{$deal['id']}/edit", ['name' => 'Software project', 'total' => 20000, 'note' => 'Website'], $this->session)
            ->assertUnprocessable()->assertJsonPath('code', 'total_below_received');
        $this->wallet('POST', "deals/{$deal['id']}/edit", ['name' => 'Software project', 'total' => 80000, 'note' => 'Website'], $this->session)
            ->assertUnprocessable()->assertJsonPath('code', 'nothing_changed');
        $this->wallet('POST', "deals/{$deal['id']}/edit", ['name' => 'Website project', 'total' => 70000, 'note' => '', 'reason' => 'Scope cut'], $this->session)->assertOk()
            ->assertJsonPath('data.name', 'Website project')->assertJsonPath('data.note', null)->assertJsonPath('data.due', 40000);
        // Its money shows under the new name everywhere.
        $this->assertSame('Website project', $this->wallet('GET', 'summary', session: $this->session)->json('data.by_source.0.name'));
        $this->wallet('GET', 'transactions', session: $this->session)->assertJsonPath('data.0.source', 'Website project')->assertJsonPath('data.0.deal.name', 'Website project');

        // Add payment: no more than what is due; then the payment edited, no higher than due with its own amount.
        $this->wallet('POST', "deals/{$deal['id']}/payments", ['amount' => 50000], $this->session)->assertUnprocessable()->assertJsonPath('code', 'payment_over_due');
        $paid = $this->wallet('POST', "deals/{$deal['id']}/payments", ['amount' => 25000, 'reference' => 'September', 'occurred_on' => '2026-09-15'], $this->session)->assertCreated()
            ->assertJsonPath('data.due', 15000)->json('data.payments');
        // Listed by date: the payment dated the 15th comes before the advance taken on the 16th.
        $this->assertSame(['September', 'Advance'], array_column($paid, 'reference'));
        $payment = $paid[0]['id'];
        $this->wallet('POST', "transactions/{$payment}/edit", ['amount' => 45000, 'reference' => 'September', 'occurred_on' => '2026-09-15'], $this->session)
            ->assertUnprocessable()->assertJsonPath('code', 'payment_over_due');
        $this->wallet('POST', "transactions/{$payment}/edit", ['amount' => 40000, 'reference' => 'September', 'occurred_on' => '2026-09-15', 'direction' => 'out', 'source_id' => 999], $this->session)->assertOk()
            ->assertJsonPath('data.direction', 'in')->assertJsonPath('data.kind', 'deal_payment')->assertJsonPath('data.deal.id', $deal['id']);
        $this->wallet('GET', 'deals', session: $this->session)->assertJsonPath('data.0.due', 0)->assertJsonPath('data.0.settled', true)->assertJsonCount(2, 'data.0.payments');

        // Deleting the advance makes it due again.
        $this->wallet('POST', "transactions/{$advance}/delete", ['reason' => 'Returned'], $this->session)->assertOk();
        $view = $this->wallet('GET', "deals/{$deal['id']}", session: $this->session)->assertOk()->assertJsonPath('data.due', 30000)->assertJsonCount(1, 'data.payments')->json('data');
        $this->assertSame(['deal_created', 'deal_edited'], array_column($view['history'], 'action'));
        $this->assertSame(['Software project', 'Website project'], $view['history'][1]['changes']['changes']['name']);
        $this->assertSame('Scope cut', $view['history'][1]['changes']['reason']);
        $this->assertSame([40000, 30000], [(int) $this->wallet('GET', 'summary', session: $this->session)->json('data.balance'), (int) $this->wallet('GET', 'summary', session: $this->session)->json('data.due_total')]);

        // Deleted with its money: the deal, its due and its payment all go.
        $this->wallet('POST', "deals/{$deal['id']}/delete", ['reason' => 'Cancelled'], $this->session)->assertOk()->assertJsonPath('data.removed', 40000);
        $this->wallet('GET', 'deals', session: $this->session)->assertJsonCount(0, 'data');
        $summary = $this->wallet('GET', 'summary', session: $this->session)->json('data');
        $this->assertSame([0, 0, []], [(int) $summary['balance'], (int) $summary['due_total'], $summary['by_source']]);
        $this->wallet('GET', "deals/{$deal['id']}", session: $this->session)->assertNotFound();
        $this->wallet('POST', "deals/{$deal['id']}/payments", ['amount' => 1000], $this->session)->assertNotFound();
        $this->wallet('POST', "deals/{$deal['id']}/edit", ['name' => 'Back', 'total' => 1000], $this->session)->assertNotFound();
        $this->wallet('POST', "deals/{$deal['id']}/delete", session: $this->session)->assertNotFound();
        $edited = Transaction::query()->where('replaces_id', $payment)->value('id');
        $this->wallet('POST', "transactions/{$edited}/delete", session: $this->session)->assertConflict()->assertJsonPath('code', 'already_changed');
        $row = collect($this->wallet('GET', 'transactions?history=1', session: $this->session)->json('data'))->firstWhere('id', $edited);
        $this->assertSame(['deleted', 'Cancelled', true], [$row['status'], $row['change_reason'], $row['deal']['deleted']]);
        $this->assertSame(1, AuditLog::query()->where('action', 'deal_deleted')->count());
    }

    #[Test]
    public function sources_and_saved_references_are_renamed_and_deleted(): void
    {
        $sources = $this->wallet('POST', 'sources', ['name' => 'Family busines'], $this->session)->assertCreated()->json('data');
        $typo = collect($sources)->firstWhere('name', 'Family busines')['id'];
        $this->wallet('POST', 'transactions', ['direction' => 'in', 'amount' => 5000, 'source_id' => $typo, 'reference' => 'Profit share'], $this->session)->assertCreated();

        // Renamed: the new name shows on the entries already made and in the breakdown.
        $this->wallet('POST', "sources/{$typo}/rename", ['name' => 'Family business'], $this->session)->assertOk()->assertJsonFragment(['name' => 'Family business']);
        $this->wallet('GET', 'transactions', session: $this->session)->assertJsonPath('data.0.source', 'Family business');
        $this->assertSame('Family business', $this->wallet('GET', 'summary', session: $this->session)->json('data.by_source.0.name'));
        $this->wallet('POST', "sources/{$typo}/rename", ['name' => 'Personal'], $this->session)->assertConflict()->assertJsonPath('code', 'source_exists');

        // Deleted from the list: entries keep its name; its name can't be given to another, but adding it brings it back.
        $this->wallet('POST', "sources/{$typo}/archive", session: $this->session)->assertOk()->assertJsonMissing(['name' => 'Family business']);
        $this->wallet('GET', 'transactions', session: $this->session)->assertJsonPath('data.0.source', 'Family business');
        $rental = collect($this->wallet('POST', 'sources', ['name' => 'Rental income'], $this->session)->json('data'))->firstWhere('name', 'Rental income')['id'];
        $this->wallet('POST', "sources/{$rental}/rename", ['name' => 'Family business'], $this->session)->assertConflict()->assertJsonPath('code', 'source_name_deleted');
        $this->wallet('POST', "sources/{$typo}/rename", ['name' => 'Something'], $this->session)->assertNotFound();
        $this->wallet('POST', 'sources', ['name' => 'Family business'], $this->session)->assertCreated();
        $this->assertNull(Source::query()->findOrFail($typo)->archived_at);

        // Saved references: reworded, never onto another of the same direction; then removed.
        $this->wallet('POST', 'presets', ['direction' => 'in', 'label' => 'Profit share'], $this->session)->assertCreated();
        $rent = collect($this->wallet('POST', 'presets', ['direction' => 'in', 'label' => 'Rent'], $this->session)->json('data'))->firstWhere('label', 'Rent')['id'];
        $this->wallet('POST', 'presets', ['direction' => 'out', 'label' => 'Rent paid'], $this->session)->assertCreated();
        $this->wallet('POST', "presets/{$rent}/edit", ['label' => 'Profit share'], $this->session)->assertConflict()->assertJsonPath('code', 'preset_exists');
        $this->wallet('POST', "presets/{$rent}/edit", ['label' => 'Rent paid'], $this->session)->assertOk();
        $this->wallet('POST', "presets/{$rent}/edit", ['label' => 'Rent income'], $this->session)->assertOk()->assertJsonFragment(['label' => 'Rent income']);
        $this->wallet('POST', "presets/{$rent}/remove", session: $this->session)->assertOk()->assertJsonMissing(['label' => 'Rent income']);
    }

    /** @param array<string, string> $fields */
    private function multipart(string $uri, array $fields, UploadedFile $file): TestResponse
    {
        $this->resetAuthState();

        return $this->withUnencryptedCookie('bh_wallet', $this->session)
            ->post("/api/v1/wallet/{$uri}", $fields + ['evidence' => $file], ['X-Wallet-Request' => '1', 'Accept' => 'application/json']);
    }
}
