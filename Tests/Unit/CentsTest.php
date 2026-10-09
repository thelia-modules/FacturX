<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacturX\Tests\Unit;

use FacturX\Domain\Cents;
use PHPUnit\Framework\TestCase;

final class CentsTest extends TestCase
{
    public function testAnAllocationAlwaysAddsUpToTheTotal(): void
    {
        $shares = Cents::allocate(100, [1, 1, 1]);

        self::assertSame(100, array_sum($shares));
        self::assertSame([34, 33, 33], $shares);
    }

    public function testAnAllocationFollowsTheWeights(): void
    {
        self::assertSame([75, 25], Cents::allocate(100, [3, 1]));
    }

    public function testAnAllocationWithoutWeightGoesToTheFirstShare(): void
    {
        self::assertSame([100, 0], Cents::allocate(100, [0, 0]));
    }

    public function testACentRoundsHalfUp(): void
    {
        self::assertSame(1, Cents::from('0.005'));
        self::assertSame(22300, Cents::from('223.000000'));
    }

    public function testAnAmountIsFormattedWithTwoDecimals(): void
    {
        self::assertSame('1234.50', Cents::format(123450));
        self::assertSame('-0.05', Cents::format(-5));
    }
}
