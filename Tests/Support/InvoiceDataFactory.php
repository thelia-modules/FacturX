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

namespace FacturX\Tests\Support;

use FacturX\Domain\Adjustment;
use FacturX\Domain\InvoiceData;
use FacturX\Domain\InvoiceLine;
use FacturX\Domain\InvoiceParty;
use FacturX\Domain\TaxBreakdownEntry;
use FacturX\Domain\TaxCategory;

final class InvoiceDataFactory
{
    public static function standard(?InvoiceParty $buyer = null, ?TaxCategory $category = null): InvoiceData
    {
        $category ??= new TaxCategory('S', '20.00');
        $taxCents = '0.00' === $category->rate ? 0 : 2000 + 200;

        return new InvoiceData(
            orderId: 1,
            orderReference: 'ORD000000000001',
            number: '2026-000001',
            issueDate: new \DateTimeImmutable('2026-09-25'),
            currencyCode: 'EUR',
            seller: new InvoiceParty('Thelia', 'FR', '5 rue Rochon', '63000', 'Clermont-Ferrand', '30326504500003', 'FR40303265045', true),
            buyer: $buyer ?? new InvoiceParty('Jane Doe', 'FR', '1 rue de Paris', '75001', 'Paris', '', '', false),
            lines: [new InvoiceLine(1, 'Product', 'REF-1', '100.00', 2, 20000, $taxCents - 200, $category)],
            taxBreakdown: [new TaxBreakdownEntry($category, 20000 + 1000, $taxCents)],
            adjustments: [new Adjustment(true, 1000, $category, 'Frais de port')],
            lineTotalCents: 20000,
            allowanceTotalCents: 0,
            chargeTotalCents: 1000,
            taxBasisTotalCents: 21000,
            taxTotalCents: $taxCents,
            grandTotalCents: 21000 + $taxCents,
            prepaidCents: 21000 + $taxCents,
            paymentTitle: 'Cheque',
            isLegacyRounding: false,
            inconsistencies: [],
            deliveryCountryCode: 'FR',
        );
    }
}
