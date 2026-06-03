<?php

namespace Tests\Unit;

use App\Enums\ServiceContractStatus as S;
use PHPUnit\Framework\TestCase;

class ServiceContractStatusTest extends TestCase
{
    public function test_only_active_is_live(): void
    {
        $this->assertTrue(S::Active->isLive());
        foreach ([S::Pending, S::Suspended, S::Cancelled, S::Terminated] as $s) {
            $this->assertFalse($s->isLive());
        }
    }

    public function test_transition_machine(): void
    {
        $this->assertTrue(S::Pending->canTransitionTo(S::Active));
        $this->assertTrue(S::Active->canTransitionTo(S::Suspended));
        $this->assertTrue(S::Active->canTransitionTo(S::Terminated));
        $this->assertTrue(S::Suspended->canTransitionTo(S::Active));
        $this->assertTrue(S::Cancelled->canTransitionTo(S::Active));   // revive

        // Terminated is terminal.
        $this->assertSame([], S::Terminated->allowedTransitions());
        $this->assertFalse(S::Terminated->canTransitionTo(S::Active));
        // Can't jump Pending → Suspended/Terminated.
        $this->assertFalse(S::Pending->canTransitionTo(S::Suspended));
        $this->assertFalse(S::Pending->canTransitionTo(S::Terminated));
    }
}
