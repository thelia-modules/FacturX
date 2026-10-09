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

use FacturX\Service\GenerationLimiter;
use FacturX\Service\GenerationPermit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GenerationLimiterTest extends TestCase
{
    public function testAnAdministratorIsCutOffAfterThirtyGenerationsInTheWindow(): void
    {
        $limiter = new GenerationLimiter(new ArrayAdapter());

        for ($generation = 1; $generation <= 30; ++$generation) {
            self::assertTrue($limiter->allows(7), 'Generation '.$generation.' should pass.');
        }

        self::assertFalse($limiter->allows(7));
        self::assertTrue($limiter->allows(8), 'The budget is per administrator.');
    }

    public function testThePermitIsOnlyOpenWhileTheGenerationRuns(): void
    {
        $permit = new GenerationPermit();

        self::assertFalse($permit->isOpen());
        $permit->during(static function () use ($permit): void {
            self::assertTrue($permit->isOpen());
        });
        self::assertFalse($permit->isOpen());

        try {
            $permit->during(static fn () => throw new \RuntimeException('failed'));
        } catch (\RuntimeException) {
        }
        self::assertFalse($permit->isOpen(), 'A failed generation does not leave the door open.');
    }
}
