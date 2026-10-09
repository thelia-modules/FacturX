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

final readonly class TaxCategory
{
    public function __construct(
        public string $code,
        public string $rate,
        public ?string $exemptionReason = null,
        public ?string $exemptionReasonCode = null,
    ) {
    }

    public function key(): string
    {
        return $this->code.'|'.$this->rate;
    }
}
