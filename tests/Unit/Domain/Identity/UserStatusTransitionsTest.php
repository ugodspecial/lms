<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\UserStatusTransitions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The user lifecycle as a table, checked exhaustively (docs/02 §1).
 *
 * Four states is sixteen pairs, and every one of them is asserted here rather than
 * a representative handful. The reason is that this is the whole of the rule: an
 * unlisted pair is not "not yet implemented", it is a change to what an account
 * can become, and a test that sampled the obvious transitions would not notice.
 *
 * The pair that matters most is the one that must never exist — `deactivated` to
 * anything. That state is what this platform uses instead of deleting a person,
 * because academic and financial rows reference `users` with ON DELETE RESTRICT, so
 * an account that could quietly become active again would be a resurrection
 * nobody authorized.
 */
final class UserStatusTransitionsTest extends TestCase
{
    /**
     * @return array<string, array{0: UserStatus, 1: UserStatus, 2: bool}>
     */
    public static function everyPair(): array
    {
        $cases = [];

        foreach (UserStatus::cases() as $from) {
            foreach (UserStatus::cases() as $to) {
                $cases[$from->value.' → '.$to->value] = [$from, $to, self::expected($from, $to)];
            }
        }

        return $cases;
    }

    // The attribute rather than `@dataProvider`: PHPUnit 12 reads metadata from
    // attributes, and an annotation it no longer parses would call this method with
    // no arguments and report an ArgumentCountError against a rule that is fine.
    #[DataProvider('everyPair')]
    public function test_the_machine_allows_exactly_the_documented_moves(UserStatus $from, UserStatus $to, bool $expected): void
    {
        $this->assertSame(
            $expected,
            UserStatusTransitions::allows($from, $to),
            sprintf('%s → %s', $from->value, $to->value),
        );
    }

    public function test_staying_where_you_are_is_not_a_transition(): void
    {
        // Not merely uninteresting: a service that accepted it would write an audit
        // entry claiming a change that did not happen.
        foreach (UserStatus::cases() as $status) {
            $this->assertFalse(
                UserStatusTransitions::allows($status, $status),
                $status->value.' should not be a successor of itself',
            );
        }
    }

    public function test_deactivated_is_terminal(): void
    {
        $this->assertTrue(UserStatusTransitions::isTerminal(UserStatus::Deactivated));
        $this->assertSame([], UserStatusTransitions::allowedFrom(UserStatus::Deactivated));

        foreach ([UserStatus::Pending, UserStatus::Active, UserStatus::Suspended] as $status) {
            $this->assertFalse(
                UserStatusTransitions::isTerminal($status),
                $status->value.' should have successors',
            );
        }
    }

    public function test_the_successors_of_each_state_are_the_enums_a_form_may_offer(): void
    {
        $this->assertSame(
            [UserStatus::Active, UserStatus::Deactivated],
            UserStatusTransitions::allowedFrom(UserStatus::Pending),
        );
        $this->assertSame(
            [UserStatus::Suspended, UserStatus::Deactivated],
            UserStatusTransitions::allowedFrom(UserStatus::Active),
        );
        $this->assertSame(
            [UserStatus::Active, UserStatus::Deactivated],
            UserStatusTransitions::allowedFrom(UserStatus::Suspended),
        );
    }

    public function test_pending_cannot_be_suspended(): void
    {
        // Suspension answers conduct by somebody who can act. A person who has not
        // verified an address cannot enrol, book, buy or upload anything, so there
        // is nothing to stop — and an administrator closing such an account has
        // `deactivated` for it.
        $this->assertFalse(UserStatusTransitions::allows(UserStatus::Pending, UserStatus::Suspended));
    }

    public function test_every_state_reaches_the_terminal_one(): void
    {
        foreach ([UserStatus::Pending, UserStatus::Active, UserStatus::Suspended] as $status) {
            $this->assertTrue(
                UserStatusTransitions::allows($status, UserStatus::Deactivated),
                $status->value.' must be closable',
            );
        }
    }

    private static function expected(UserStatus $from, UserStatus $to): bool
    {
        return match (true) {
            $from === UserStatus::Pending => $to === UserStatus::Active || $to === UserStatus::Deactivated,
            $from === UserStatus::Active => $to === UserStatus::Suspended || $to === UserStatus::Deactivated,
            $from === UserStatus::Suspended => $to === UserStatus::Active || $to === UserStatus::Deactivated,
            default => false,
        };
    }
}
