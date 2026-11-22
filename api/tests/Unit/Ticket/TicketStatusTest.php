<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticket;

use App\Module\Ticket\TicketStatus as S;
use PHPUnit\Framework\TestCase;

final class TicketStatusTest extends TestCase
{
    public function testTransitionsFollowTheTicketsSpecification(): void
    {
        $allowed = [
            'received' => ['interpreting', 'needs_intervention', 'rejected'],
            'interpreting' => ['needs_clarification', 'awaiting_confirmation', 'queued', 'rejected', 'needs_intervention'],
            'needs_clarification' => ['interpreting', 'needs_intervention', 'rejected'],
            'awaiting_confirmation' => ['queued', 'needs_clarification', 'rejected'],
            'queued' => ['running', 'needs_intervention', 'rejected'],
            'running' => ['completed', 'needs_intervention'],
            'needs_intervention' => ['interpreting', 'queued', 'rejected'],
            'completed' => [],
            'rejected' => [],
        ];
        foreach (S::cases() as $from) {
            $actual = array_values(array_map(static fn (S $s): string => $s->value, array_filter(S::cases(), $from->canBecome(...))));
            sort($actual);
            $expected = $allowed[$from->value];
            sort($expected);
            self::assertSame($expected, $actual, $from->value);
            self::assertSame([] !== $expected, $from->isOpen(), $from->value);
        }
    }
}
