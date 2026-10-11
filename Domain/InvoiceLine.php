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

final readonly class InvoiceLine
{
    public function __construct(
        public int $position,
        public string $name,
        public string $reference,
        public string $unitPrice,
        public float $quantity,
        public int $netCents,
        public int $taxCents,
        public TaxCategory $category,
    ) {
    }
}
