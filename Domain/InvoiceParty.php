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

final readonly class InvoiceParty
{
    public function __construct(
        public string $name,
        public string $countryCode,
        public string $addressLine,
        public string $postcode,
        public string $city,
        public string $siret,
        public string $vatNumber,
        public bool $isCompany,
    ) {
    }

    public function siren(): string
    {
        $digits = preg_replace('/\D/', '', $this->siret) ?? '';

        return 14 === \strlen($digits) ? substr($digits, 0, 9) : '';
    }
}
