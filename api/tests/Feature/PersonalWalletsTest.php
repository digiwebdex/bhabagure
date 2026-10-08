<?php

namespace Tests\Feature;

use App\Wallet\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/personal-wallets.md: the super admin gives a personal wallet to staff they choose; each wallet is its owner's
 * alone — nobody, the super admin included, reads or touches another's.
 */
class PersonalWalletsTest extends TestCase
{
    use UsesWalletDatabase;

    protected $connectionsToTransact = [null, 'wallet'];

    #[Test]
    public function only_staff_given_a_wallet_open_one_and_the_super_admin_decides_who(): void
    {
        $owner = $this->staff('super_admin');
        $agent = $this->staff('sales_agent', ['name' => 'Jannat']);
        $other = $this->staff('accountant');
        $boss = $this->walletSignIn($owner->email);

        // Not given a wallet: the password step refuses, as for a wrong password.
        $this->wallet('POST', 'auth/password', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        $list = $this->wallet('GET', 'members', session: $boss)->assertOk()->json('data');
        $this->assertTrue(collect($list)->firstWhere('staff_id', $owner->id)['has_wallet']);
        $this->assertFalse(collect($list)->firstWhere('staff_id', $agent->id)['has_wallet']);
        $this->wallet('POST', 'members', ['staff_id' => $agent->id], $boss)->assertOk();
        $this->wallet('POST', 'members', ['staff_id' => 999999], $boss)->assertUnprocessable()->assertJsonPath('code', 'staff_unknown');

        $mine = $this->walletSignIn($agent->email);
        $this->wallet('GET', 'auth/me', session: $mine)->assertOk()->assertJsonPath('data.super_admin', false);
        $this->wallet('GET', 'auth/me', session: $boss)->assertOk()->assertJsonPath('data.super_admin', true);
        // Only the super admin sees or changes who has a wallet.
        $this->wallet('GET', 'members', session: $mine)->assertForbidden();
        $this->wallet('POST', 'members', ['staff_id' => $other->id], $mine)->assertForbidden();
        $listed = collect($this->wallet('GET', 'members', session: $boss)->json('data'))->firstWhere('staff_id', $agent->id);
        $this->assertSame([true, true], [$listed['has_wallet'], $listed['authenticator_set']]);

        // Taken away: their session ends at once and the password no longer opens it.
        $this->wallet('POST', "members/{$agent->id}/revoke", session: $boss)->assertOk();
        $this->wallet('GET', 'summary', session: $mine)->assertUnauthorized();
        $this->wallet('POST', 'auth/password', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertUnauthorized();
        $this->wallet('POST', "members/{$owner->id}/revoke", session: $boss)->assertNotFound();

        // Given back, it opens again; a reset authenticator is set up again at the next sign-in.
        $this->wallet('POST', 'members', ['staff_id' => $agent->id], $boss)->assertOk();
        $this->wallet('POST', "members/{$agent->id}/reset-authenticator", session: $boss)->assertOk();
        $this->wallet('POST', 'auth/password', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertOk()->assertJsonPath('data.enrolled', false);
    }

    #[Test]
    public function each_wallet_is_its_owners_alone_and_the_super_admin_cannot_read_or_touch_another(): void
    {
        Storage::fake('local');
        $owner = $this->staff('super_admin');
        $agent = $this->staff('sales_agent');
        $boss = $this->walletSignIn($owner->email);
        $this->wallet('POST', 'members', ['staff_id' => $agent->id], $boss)->assertOk();
        $mine = $this->walletSignIn($agent->email);

        // Each starts with their own Personal and Other; a source added by one isn't in the other's list.
        $agentSources = $this->wallet('GET', 'sources', session: $mine)->assertOk()->json('data');
        $this->assertSame(['Personal', 'Other'], array_column($agentSources, 'name'));
        $bossSources = $this->wallet('POST', 'sources', ['name' => 'Rental income'], $boss)->assertCreated()->json('data');
        $this->assertNotContains('Rental income', array_column($this->wallet('GET', 'sources', session: $mine)->json('data'), 'name'));
        // Another wallet's source can't be used or archived.
        $bossRental = collect($bossSources)->firstWhere('name', 'Rental income')['id'];
        $this->wallet('POST', 'transactions', ['direction' => 'in', 'amount' => 500, 'source_id' => $bossRental, 'reference' => 'Not mine'], $mine)->assertUnprocessable()->assertJsonPath('code', 'source_unknown');
        $this->wallet('POST', "sources/{$bossRental}/archive", session: $mine)->assertNotFound();

        $bossEntry = $this->withUnencryptedCookie('bh_wallet', $boss)->post('/api/v1/wallet/transactions', [
            'direction' => 'in', 'amount' => '90000', 'source_id' => (string) $bossRental, 'reference' => 'September rent', 'evidence' => UploadedFile::fake()->create('slip.pdf', 10, 'application/pdf'),
        ], ['X-Wallet-Request' => '1', 'Accept' => 'application/json'])->assertCreated()->json('data.id');
        $bossDeal = $this->wallet('POST', 'deals', ['name' => 'Land sale', 'total' => 500000, 'advance' => 100000], $boss)->assertCreated()->json('data.id');
        $this->wallet('POST', 'presets', ['direction' => 'in', 'label' => 'Rent'], $boss)->assertCreated();

        $agentPersonal = $agentSources[0]['id'];
        $this->wallet('POST', 'transactions', ['direction' => 'in', 'amount' => 3000, 'source_id' => $agentPersonal, 'reference' => 'Gift'], $mine)->assertCreated();

        // Each sees only their own figures, history, deals and saved references.
        $this->assertSame(3000, (int) $this->wallet('GET', 'summary', session: $mine)->json('data.balance'));
        $this->assertSame(190000, (int) $this->wallet('GET', 'summary', session: $boss)->json('data.balance'));
        $this->assertSame(['Gift'], array_column($this->wallet('GET', 'transactions', session: $mine)->json('data'), 'reference'));
        $this->wallet('GET', 'deals', session: $mine)->assertOk()->assertJsonCount(0, 'data');
        $this->wallet('GET', 'presets', session: $mine)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, (int) $this->wallet('GET', 'summary', session: $mine)->json('data.due_total'));

        // Another wallet's entry, evidence and deal are not found, whoever asks.
        $this->wallet('POST', "transactions/{$bossEntry}/reverse", ['reason' => 'Trying'], $mine)->assertNotFound();
        $this->wallet('GET', "transactions/{$bossEntry}/evidence", session: $mine)->assertNotFound();
        $this->wallet('POST', "deals/{$bossDeal}/payments", ['amount' => 1000], $mine)->assertNotFound();
        $agentEntry = Transaction::query()->where('created_by_staff_id', $agent->id)->value('id');
        $this->wallet('POST', "transactions/{$agentEntry}/reverse", ['reason' => 'Trying'], $boss)->assertNotFound();
        // Access shows who has a wallet, never what is in it.
        $this->assertSame(
            ['staff_id', 'name', 'email', 'super_admin', 'has_wallet', 'granted_at', 'authenticator_set'],
            array_keys(collect($this->wallet('GET', 'members', session: $boss)->json('data'))->firstWhere('staff_id', $agent->id)),
        );
    }
}
