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

use Atgp\FacturX\XsdValidator;
use FacturX\Domain\InvoiceParty;
use FacturX\Domain\Profile;
use FacturX\Domain\TaxCategory;
use FacturX\Service\CiiXmlBuilder;
use FacturX\Tests\Support\InvoiceDataFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CiiXmlBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{Profile}>
     */
    public static function profiles(): iterable
    {
        foreach (Profile::cases() as $profile) {
            yield $profile->value => [$profile];
        }
    }

    #[DataProvider('profiles')]
    public function testTheXmlOfEveryProfileIsValidAgainstItsSchema(Profile $profile): void
    {
        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard(), $profile);

        (new XsdValidator())->validateWithException($xml, $profile->value);

        self::assertStringContainsString('<ram:ID>'.$profile->guidelineIdentifier().'</ram:ID>', $xml);
    }

    #[DataProvider('profiles')]
    public function testTheDeliveryBlockIsNeverLeftEmptyInTheProfilesThatCarryLines(Profile $profile): void
    {
        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard(), $profile);

        if (!$profile->carriesLines()) {
            self::assertStringNotContainsString('ShipToTradeParty', $xml, 'A profile without lines has no delivery party.');

            return;
        }

        self::assertStringContainsString('<ram:ApplicableHeaderTradeDelivery><ram:ShipToTradeParty><ram:PostalTradeAddress><ram:CountryID>FR</ram:CountryID></ram:PostalTradeAddress></ram:ShipToTradeParty></ram:ApplicableHeaderTradeDelivery>', $xml);
    }

    public function testTheTotalsAreTheOnesOfTheInvoiceData(): void
    {
        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard(), Profile::En16931);

        self::assertStringContainsString('<ram:LineTotalAmount>200.00</ram:LineTotalAmount>', $xml);
        self::assertStringContainsString('<ram:ChargeTotalAmount>10.00</ram:ChargeTotalAmount>', $xml);
        self::assertStringContainsString('<ram:TaxBasisTotalAmount>210.00</ram:TaxBasisTotalAmount>', $xml);
        self::assertStringContainsString('<ram:GrandTotalAmount>232.00</ram:GrandTotalAmount>', $xml);
        self::assertStringContainsString('<ram:DuePayableAmount>0.00</ram:DuePayableAmount>', $xml);
    }

    public function testTheBuyerIdentifiersAreWritten(): void
    {
        $buyer = new InvoiceParty('Acme', 'BE', '1 rue de Mons', '7000', 'Mons', '30326504500003', 'BE0417497106', true);

        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard($buyer), Profile::En16931);

        self::assertMatchesRegularExpression('#<ram:BuyerTradeParty>.*<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">303265045</ram:ID>.*<ram:ID schemeID="VA">BE0417497106</ram:ID>.*</ram:BuyerTradeParty>#s', $xml);
    }

    public function testAnIntraCommunitySupplyCarriesItsExemptionReason(): void
    {
        $category = new TaxCategory('K', '0.00', 'Livraison intracommunautaire', 'VATEX-EU-IC');
        $buyer = new InvoiceParty('Acme', 'BE', '1 rue de Mons', '7000', 'Mons', '', 'BE0417497106', true);

        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard($buyer, $category), Profile::En16931);

        (new XsdValidator())->validateWithException($xml, Profile::En16931->value);
        self::assertStringContainsString('<ram:CategoryCode>K</ram:CategoryCode><ram:ExemptionReasonCode>VATEX-EU-IC</ram:ExemptionReasonCode>', $xml);
        self::assertStringContainsString('<ram:CalculatedAmount>0.00</ram:CalculatedAmount>', $xml);
    }

    public function testTheMinimumProfileCarriesNeitherLinesNorBreakdown(): void
    {
        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard(), Profile::Minimum);

        self::assertStringNotContainsString('IncludedSupplyChainTradeLineItem', $xml);
        self::assertStringNotContainsString('SpecifiedTradeAllowanceCharge', $xml);
    }

    public function testTextIsEscaped(): void
    {
        $buyer = new InvoiceParty('Fish & <Chips> "Ltd"', 'FR', '1 rue de Paris', '75001', 'Paris', '', '', true);

        $xml = (new CiiXmlBuilder())->build(InvoiceDataFactory::standard($buyer), Profile::En16931);

        (new XsdValidator())->validateWithException($xml, Profile::En16931->value);
        self::assertStringContainsString('Fish &amp; &lt;Chips&gt;', $xml);
    }
}
