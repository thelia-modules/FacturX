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

use FacturX\Domain\InvoiceData;
use FacturX\Domain\InvoiceParty;
use FacturX\Domain\Profile;
use FacturX\Domain\TaxCategory;
use FacturX\Service\CompletenessChecker;
use FacturX\Tests\Support\InvoiceDataFactory;
use PHPUnit\Framework\TestCase;

final class CompletenessCheckerTest extends TestCase
{
    public function testAnInvoiceWithEverythingIsComplete(): void
    {
        $report = (new CompletenessChecker())->check(InvoiceDataFactory::standard(), Profile::En16931);

        self::assertTrue($report->isComplete());
        self::assertSame([], $report->missing);
    }

    public function testASellerWithoutVatNumberCannotIssueAnInvoiceThatCarriesVat(): void
    {
        $data = $this->with(seller: new InvoiceParty('Thelia', 'FR', '5 rue Rochon', '63000', 'Clermont-Ferrand', '30326504500003', '', true));

        $report = (new CompletenessChecker())->check($data, Profile::En16931);

        self::assertSame([CompletenessChecker::SELLER_VAT_NUMBER], $report->missing);
    }

    public function testASellerWithoutAnyIdentifierIsReported(): void
    {
        $data = $this->with(seller: new InvoiceParty('Thelia', 'FR', '5 rue Rochon', '63000', 'Clermont-Ferrand', '', '', true), category: new TaxCategory('E', '0.00', 'x', 'VATEX-FR-FRANCHISE'));

        $report = (new CompletenessChecker())->check($data, Profile::En16931);

        self::assertContains(CompletenessChecker::SELLER_IDENTIFIER, $report->missing);
    }

    public function testACompanyWithoutVatNumberIsRefusedWhenTheShopAsksForIt(): void
    {
        $company = new InvoiceParty('Acme', 'FR', '1 rue de Paris', '75001', 'Paris', '', '', true);
        $data = InvoiceDataFactory::standard($company);

        self::assertTrue((new CompletenessChecker())->check($data, Profile::En16931)->isComplete());
        self::assertSame(
            [CompletenessChecker::BUYER_VAT_NUMBER],
            (new CompletenessChecker())->check($data, Profile::En16931, requireBuyerVat: true)->missing,
        );
    }

    public function testAnIndividualIsNeverAskedForAVatNumber(): void
    {
        $report = (new CompletenessChecker())->check(InvoiceDataFactory::standard(), Profile::En16931, requireBuyerVat: true);

        self::assertTrue($report->isComplete());
    }

    public function testAnIntraCommunitySupplyNeedsTheBuyerVatNumber(): void
    {
        $buyer = new InvoiceParty('Acme', 'BE', '1 rue de Mons', '7000', 'Mons', '', '', true);
        $data = InvoiceDataFactory::standard($buyer, new TaxCategory('K', '0.00', 'x', 'VATEX-EU-IC'));

        $report = (new CompletenessChecker())->check($data, Profile::En16931);

        self::assertSame([CompletenessChecker::BUYER_VAT_NUMBER], $report->missing);
    }

    public function testAMissingPaymentMethodIsReportedUnlessTheProfileIgnoresIt(): void
    {
        $data = $this->with(paymentTitle: ' ');

        self::assertSame([CompletenessChecker::PAYMENT_MEANS], (new CompletenessChecker())->check($data, Profile::En16931)->missing);
        self::assertTrue((new CompletenessChecker())->check($data, Profile::Minimum)->isComplete());
    }

    public function testAnInvoiceThatWasNotIssuedYetIsReported(): void
    {
        $data = $this->with(number: null, issueDate: null);

        self::assertContains(CompletenessChecker::NOT_INVOICED, (new CompletenessChecker())->check($data, Profile::En16931)->missing);
    }

    public function testTotalsThatDisagreeWithTheOrderAreReported(): void
    {
        $data = $this->with(inconsistencies: ['grand total 232.00 differs from the order total 231.99']);

        self::assertSame([CompletenessChecker::INCONSISTENT_TOTALS], (new CompletenessChecker())->check($data, Profile::En16931)->missing);
    }

    private function with(
        ?InvoiceParty $seller = null,
        ?TaxCategory $category = null,
        ?string $paymentTitle = null,
        string|false|null $number = false,
        ?\DateTimeInterface $issueDate = null,
        array $inconsistencies = [],
    ): InvoiceData {
        $base = InvoiceDataFactory::standard(category: $category);

        return new InvoiceData(
            $base->orderId,
            $base->orderReference,
            false === $number ? $base->number : $number,
            false === $number ? $base->issueDate : $issueDate,
            $base->currencyCode,
            $seller ?? $base->seller,
            $base->buyer,
            $base->lines,
            $base->taxBreakdown,
            $base->adjustments,
            $base->lineTotalCents,
            $base->allowanceTotalCents,
            $base->chargeTotalCents,
            $base->taxBasisTotalCents,
            $base->taxTotalCents,
            $base->grandTotalCents,
            $base->prepaidCents,
            $paymentTitle ?? $base->paymentTitle,
            $base->isLegacyRounding,
            $inconsistencies,
        );
    }
}
