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

namespace FacturX\Domain;

final class Cents
{
    private function __construct()
    {
    }

    public static function from(string|int|float|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param list<float|int> $weights
     *
     * @return list<int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $count = \count($weights);
        if (0 === $count) {
            return [];
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            $shares = array_fill(0, $count, 0);
            $shares[0] = $total;

            return $shares;
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $index => $weight) {
            $exact = $total * $weight / $sum;
            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $shares[$index];
            $allocated += $shares[$index];
        }

        arsort($remainders);
        foreach (array_keys($remainders) as $index) {
            if ($allocated >= $total) {
                break;
            }
            ++$shares[$index];
            ++$allocated;
        }

        return $shares;
    }
}
