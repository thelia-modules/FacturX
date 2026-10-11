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

namespace FacturX\Service;

use FacturX\Domain\Adjustment;
use FacturX\Domain\Cents;
use FacturX\Domain\InvoiceData;
use FacturX\Domain\InvoiceLine;
use FacturX\Domain\InvoiceParty;
use FacturX\Domain\TaxBreakdownEntry;
use FacturX\Domain\TaxCategory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderProduct;

final readonly class InvoiceDataBuilder
{
    private const INTRA_COMMUNITY_REASON = 'Livraison intracommunautaire exonérée de TVA, article 262 ter I du CGI';
    private const FRANCHISE_REASON = 'TVA non applicable, article 293 B du CGI';

    public function __construct(
        private InvoiceNumberResolver $numberResolver,
        private Configuration $configuration,
    ) {
    }

    public function build(Order $order): InvoiceData
    {
        $orderId = (int) $order->getId();
        $isLegacy = ConfigQuery::isOrderWithLegacyRounding($orderId);
        $roundingOfSums = ConfigQuery::ROUNDING_MODE_ROUNDING_OF_SUMS === ConfigQuery::getOrderRoundingMode($orderId);
        $isExempted = $order->getVatExempted();
        $isShopExempted = ConfigQuery::isStoreVatExempt();

        $lines = $this->buildLines($order, $roundingOfSums, $isExempted, $isShopExempted);

        $taxWithoutDiscount = 0.0;
        $order->getTotalAmount($taxWithoutDiscount, false, false);
        $taxWithDiscount = 0.0;
        $order->getTotalAmount($taxWithDiscount, false, true);

        $discountCents = Cents::from($order->getDiscount());
        $discountTaxCents = Cents::from($taxWithoutDiscount) - Cents::from($taxWithDiscount);
        $discountNetCents = $discountCents - $discountTaxCents;

        $postageNetCents = Cents::from($order->getUntaxedPostage());
        $postageTaxCents = Cents::from($order->getPostageTax());
        $postageCategory = $this->category(
            $postageNetCents > 0 ? $this->snapRate(100 * $postageTaxCents / $postageNetCents) : 0.0,
            $isExempted,
            $isShopExempted,
        );

        $groups = $this->groupLines($lines);
        [$breakdown, $adjustments] = $this->assemble(
            $groups,
            $discountNetCents,
            $discountTaxCents,
            $postageNetCents,
            $postageTaxCents,
            $postageCategory,
        );

        $lineTotal = array_sum(array_map(static fn (InvoiceLine $line): int => $line->netCents, $lines));
        $taxBasisTotal = $lineTotal - $discountNetCents + $postageNetCents;
        $taxTotal = array_sum(array_map(static fn (TaxBreakdownEntry $entry): int => $entry->taxCents, $breakdown));
        $grandTotal = $taxBasisTotal + $taxTotal;

        $theliaTax = 0.0;
        $theliaGrandTotal = Cents::from($order->getTotalAmount($theliaTax));

        $inconsistencies = [];
        if ($grandTotal !== $theliaGrandTotal) {
            $inconsistencies[] = \sprintf('grand total %s differs from the order total %s', Cents::format($grandTotal), Cents::format($theliaGrandTotal));
        }
        if ($taxTotal !== Cents::from($theliaTax)) {
            $inconsistencies[] = \sprintf('tax total %s differs from the order tax %s', Cents::format($taxTotal), Cents::format(Cents::from($theliaTax)));
        }

        return new InvoiceData(
            orderId: $orderId,
            orderReference: $order->getRef(),
            number: $this->numberResolver->resolve($order),
            issueDate: $order->getInvoiceDate(),
            currencyCode: (string) $order->getCurrency()->getCode(),
            seller: $this->seller(),
            buyer: $this->party($order->getOrderAddressRelatedByInvoiceOrderAddressId()),
            lines: $lines,
            taxBreakdown: $breakdown,
            adjustments: $adjustments,
            lineTotalCents: $lineTotal,
            allowanceTotalCents: $discountNetCents,
            chargeTotalCents: $postageNetCents,
            taxBasisTotalCents: $taxBasisTotal,
            taxTotalCents: $taxTotal,
            grandTotalCents: $grandTotal,
            prepaidCents: $order->isPaid(false) ? $grandTotal : 0,
            paymentTitle: $this->paymentTitle($order),
            isLegacyRounding: $isLegacy,
            inconsistencies: $inconsistencies,
            deliveryCountryCode: $this->countryCode((int) $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getCountryId()),
        );
    }

    /**
     * @return list<InvoiceLine>
     */
    private function buildLines(Order $order, bool $roundingOfSums, bool $isExempted, bool $isShopExempted): array
    {
        $rows = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            /** @var OrderProduct $orderProduct */
            $isPromo = (bool) $orderProduct->getWasInPromo();
            $unitPrice = (float) ($isPromo ? $orderProduct->getPromoPrice() : $orderProduct->getPrice());
            $unitTax = 0.0;
            $roundedUnitTax = 0.0;
            foreach ($orderProduct->getOrderProductTaxes() as $productTax) {
                $taxAmount = (float) ($isPromo ? $productTax->getPromoAmount() : $productTax->getAmount());
                $unitTax += $taxAmount;
                $roundedUnitTax += round($taxAmount, 2);
            }

            $quantity = (float) $orderProduct->getQuantity();
            if ($roundingOfSums) {
                $net = (float) Cents::from(round($quantity * $unitPrice, 2));
                $taxed = (float) Cents::from(round($quantity * ($unitPrice + $unitTax), 2));
            } else {
                $net = $quantity * Cents::from(round($unitPrice, 2));
                $taxed = $quantity * Cents::from(round($unitPrice, 2) + $roundedUnitTax);
            }

            $rate = $unitPrice > 0 ? $this->snapRate(100 * $unitTax / $unitPrice) : 0.0;

            $rows[] = [
                'name' => (string) $orderProduct->getTitle(),
                'reference' => (string) $orderProduct->getProductSaleElementsRef(),
                'unitPrice' => $roundingOfSums ? $this->trimDecimals($unitPrice) : number_format(round($unitPrice, 2), 2, '.', ''),
                'quantity' => $quantity,
                'net' => $net,
                'tax' => $taxed - $net,
                'category' => $this->category($rate, $isExempted, $isShopExempted),
            ];
        }

        [$netCents, $taxCents] = $this->lineCents(array_column($rows, 'net'), array_column($rows, 'tax'));

        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = new InvoiceLine(
                position: $index + 1,
                name: $row['name'],
                reference: $row['reference'],
                unitPrice: $row['unitPrice'],
                quantity: $row['quantity'],
                netCents: $netCents[$index],
                taxCents: $taxCents[$index],
                category: $row['category'],
            );
        }

        return $this->attachFreeLinesToDominantCategory($lines);
    }

    /**
     * In the sum-of-roundings mode the core rounds the unit amounts but not the line totals:
     * a fractional quantity leaves a line on a fraction of a cent, and only the order total is
     * rounded. The lines then share the rounded totals the core charged, cent by cent. In the
     * rounding-of-sums mode every line is already a whole number of cents and keeps it.
     *
     * @param list<float> $nets
     * @param list<float> $taxes
     *
     * @return array{list<int>, list<int>}
     */
    private function lineCents(array $nets, array $taxes): array
    {
        $taxTotal = (int) round(array_sum($taxes));
        $netTotal = (int) round(array_sum($nets) + array_sum($taxes)) - $taxTotal;

        return [Cents::allocate($netTotal, $nets), Cents::allocate($taxTotal, $taxes)];
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return list<InvoiceLine>
     */
    private function attachFreeLinesToDominantCategory(array $lines): array
    {
        $dominant = null;
        $dominantNet = -1;
        $netByCategory = [];
        foreach ($lines as $line) {
            $key = $line->category->key();
            $netByCategory[$key] = ($netByCategory[$key] ?? 0) + $line->netCents;
            if ($line->netCents > 0 && $netByCategory[$key] > $dominantNet) {
                $dominant = $line->category;
                $dominantNet = $netByCategory[$key];
            }
        }

        if (null === $dominant) {
            return $lines;
        }

        return array_map(
            static fn (InvoiceLine $line): InvoiceLine => 0 === $line->netCents && 0 === $line->taxCents
                ? new InvoiceLine($line->position, $line->name, $line->reference, $line->unitPrice, $line->quantity, 0, 0, $dominant)
                : $line,
            $lines,
        );
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return array<string, array{category: TaxCategory, net: int, tax: int}>
     */
    private function groupLines(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line->category->key();
            $groups[$key] ??= ['category' => $line->category, 'net' => 0, 'tax' => 0];
            $groups[$key]['net'] += $line->netCents;
            $groups[$key]['tax'] += $line->taxCents;
        }

        return $groups;
    }

    /**
     * @param array<string, array{category: TaxCategory, net: int, tax: int}> $groups
     *
     * @return array{list<TaxBreakdownEntry>, list<Adjustment>}
     */
    private function assemble(
        array $groups,
        int $discountNetCents,
        int $discountTaxCents,
        int $postageNetCents,
        int $postageTaxCents,
        TaxCategory $postageCategory,
    ): array {
        $keys = array_keys($groups);
        $netWeights = array_map(static fn (array $group): int => $group['net'], array_values($groups));
        $taxWeights = array_map(static fn (array $group): float => $group['net'] * (float) $group['category']->rate, array_values($groups));

        $discountNetShares = Cents::allocate($discountNetCents, $netWeights);
        $discountTaxShares = Cents::allocate($discountTaxCents, array_sum($taxWeights) > 0 ? $taxWeights : $netWeights);

        $breakdown = [];
        $adjustments = [];
        foreach ($keys as $index => $key) {
            $group = $groups[$key];
            $basis = $group['net'] - $discountNetShares[$index];
            $tax = $group['tax'] - $discountTaxShares[$index];

            if ($key === $postageCategory->key()) {
                $basis += $postageNetCents;
                $tax += $postageTaxCents;
            }

            $breakdown[] = new TaxBreakdownEntry($group['category'], $basis, $tax);

            if ($discountNetShares[$index] > 0) {
                $adjustments[] = new Adjustment(false, $discountNetShares[$index], $group['category'], 'Remise');
            }
        }

        if ($postageNetCents > 0 && !isset($groups[$postageCategory->key()])) {
            $breakdown[] = new TaxBreakdownEntry($postageCategory, $postageNetCents, $postageTaxCents);
        }

        if ($postageNetCents > 0) {
            $adjustments[] = new Adjustment(true, $postageNetCents, $postageCategory, 'Frais de port');
        }

        return [$breakdown, $adjustments];
    }

    private function paymentTitle(Order $order): string
    {
        $title = trim((string) $order->getPaymentModuleTitle());
        if ('' !== $title) {
            return $title;
        }

        $module = $order->getModuleRelatedByPaymentModuleId();
        if (null === $module) {
            return '';
        }

        $module->setLocale($order->getLang()->getLocale());

        return '' !== trim((string) $module->getTitle()) ? trim((string) $module->getTitle()) : (string) $module->getCode();
    }

    private function category(float $rate, bool $isExempted, bool $isShopExempted): TaxCategory
    {
        if ($isExempted) {
            return new TaxCategory('K', '0.00', self::INTRA_COMMUNITY_REASON, 'VATEX-EU-IC');
        }

        if ($rate <= 0.0) {
            return $isShopExempted
                ? new TaxCategory('E', '0.00', self::FRANCHISE_REASON, 'VATEX-FR-FRANCHISE')
                : new TaxCategory('Z', '0.00');
        }

        return new TaxCategory('S', number_format($rate, 2, '.', ''));
    }

    private function snapRate(float $rate): float
    {
        $nearestHalf = round($rate * 2) / 2;

        return abs($rate - $nearestHalf) <= 0.15 ? $nearestHalf : round($rate, 2);
    }

    private function trimDecimals(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.00';
    }

    private function seller(): InvoiceParty
    {
        $siret = ConfigQuery::getStoreSiret();
        $vatNumber = ConfigQuery::getStoreVatIntracom();

        return new InvoiceParty(
            name: (string) ConfigQuery::getStoreName(),
            countryCode: $this->countryCode((int) ConfigQuery::getStoreCountry()),
            addressLine: (string) ConfigQuery::read('store_address1', ''),
            postcode: (string) ConfigQuery::read('store_zipcode', ''),
            city: (string) ConfigQuery::read('store_city', ''),
            siret: '' !== $siret ? $siret : $this->configuration->fallbackSiret(),
            vatNumber: '' !== $vatNumber ? $vatNumber : $this->configuration->fallbackVatNumber(),
            isCompany: true,
        );
    }

    private function party(?OrderAddress $address): InvoiceParty
    {
        if (null === $address) {
            return new InvoiceParty('', '', '', '', '', '', '', false);
        }

        $company = trim((string) $address->getCompany());

        return new InvoiceParty(
            name: '' !== $company ? $company : trim($address->getFirstname().' '.$address->getLastname()),
            countryCode: $this->countryCode((int) $address->getCountryId()),
            addressLine: (string) $address->getAddress1(),
            postcode: (string) $address->getZipcode(),
            city: (string) $address->getCity(),
            siret: (string) $address->getSiret(),
            vatNumber: (string) $address->getVatNumber(),
            isCompany: '' !== $company,
        );
    }

    private function countryCode(int $countryId): string
    {
        if ($countryId <= 0) {
            return '';
        }

        return (string) CountryQuery::create()->findPk($countryId)?->getIsoalpha2();
    }
}
