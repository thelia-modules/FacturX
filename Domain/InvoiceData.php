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

final readonly class InvoiceData
{
    /**
     * @param list<InvoiceLine>       $lines
     * @param list<TaxBreakdownEntry> $taxBreakdown
     * @param list<Adjustment>        $adjustments
     * @param list<string>            $inconsistencies
     */
    public function __construct(
        public int $orderId,
        public string $orderReference,
        public ?string $number,
        public ?\DateTimeInterface $issueDate,
        public string $currencyCode,
        public InvoiceParty $seller,
        public InvoiceParty $buyer,
        public array $lines,
        public array $taxBreakdown,
        public array $adjustments,
        public int $lineTotalCents,
        public int $allowanceTotalCents,
        public int $chargeTotalCents,
        public int $taxBasisTotalCents,
        public int $taxTotalCents,
        public int $grandTotalCents,
        public int $prepaidCents,
        public string $paymentTitle,
        public bool $isLegacyRounding,
        public array $inconsistencies,
        public string $deliveryCountryCode = '',
    ) {
    }

    public function dueCents(): int
    {
        return $this->grandTotalCents - $this->prepaidCents;
    }

    public function hasCategory(string $code): bool
    {
        foreach ($this->taxBreakdown as $entry) {
            if ($code === $entry->category->code) {
                return true;
            }
        }

        return false;
    }
}
