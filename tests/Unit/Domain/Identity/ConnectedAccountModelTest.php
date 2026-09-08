<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Domain\Identity\Models\ConnectedAccount;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a connected account record claims about itself, before any provider is
 * called (docs/02 §3.7, docs/11 provider-abstraction ADR).
 *
 * The distinction under test is between "the row says connected" and "the token
 * can be used". An integration that conflates them will attempt a Meet or Zoom
 * call with a dead token and surface the failure as a broken meeting rather than
 * as a reconnection prompt.
 */
final class ConnectedAccountModelTest extends TestCase
{
    private function account(
        ConnectedAccountStatus $status = ConnectedAccountStatus::Connected,
        ?Carbon $expiresAt = null,
        ?string $refreshToken = null,
    ): ConnectedAccount {
        $account = new ConnectedAccount;
        $account->provider = ConnectedProvider::Google;
        $account->purpose = ConnectedPurpose::Meetings;
        $account->status = $status;
        $account->expires_at = $expiresAt;
        $account->refresh_token = $refreshToken;

        return $account;
    }

    public function test_a_connected_token_with_no_recorded_expiry_is_usable(): void
    {
        $this->assertTrue($this->account()->isUsable());
    }

    public function test_a_connected_token_that_has_not_expired_is_usable(): void
    {
        $this->assertTrue($this->account(expiresAt: Carbon::now()->addHour())->isUsable());
    }

    public function test_a_connected_token_past_its_expiry_is_not_usable(): void
    {
        // Expiry is the common case for Zoom's short-lived S2S tokens and for
        // Google's one-hour access tokens. Treating "connected" as "current" is
        // how a working integration starts failing an hour after it is set up.
        $this->assertFalse($this->account(expiresAt: Carbon::now()->subMinute())->isUsable());
    }

    public function test_a_revoked_or_errored_link_is_not_usable_whatever_its_expiry_says(): void
    {
        $future = Carbon::now()->addYear();

        foreach ([ConnectedAccountStatus::Revoked, ConnectedAccountStatus::Error, ConnectedAccountStatus::Expired] as $status) {
            $this->assertFalse(
                $this->account(status: $status, expiresAt: $future)->isUsable(),
                $status->value.' must not read as usable'
            );
        }
    }

    public function test_refresh_is_possible_only_when_a_refresh_token_is_held(): void
    {
        // Some scopes and some purposes yield no refresh token at all. Whether an
        // expired link can be repaired silently, or needs the user back in the
        // browser, is decided by this and nothing else.
        $this->assertFalse($this->account()->canRefresh());
        $this->assertFalse($this->account(refreshToken: '')->canRefresh());
        $this->assertTrue($this->account(refreshToken: 'refresh-token-value')->canRefresh());
    }

    public function test_nothing_on_a_connected_account_is_mass_assignable(): void
    {
        // A token row asserts who is linked, to what, and with which credentials.
        // Written from a request array, a crafted post could relink somebody
        // else's provider account or replace a stored token.
        $account = new ConnectedAccount;

        $this->assertSame([], $account->getFillable());
        $this->assertTrue($account->totallyGuarded());
    }
}
