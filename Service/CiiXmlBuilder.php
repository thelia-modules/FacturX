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
use FacturX\Domain\Profile;
use FacturX\Domain\TaxBreakdownEntry;

final readonly class CiiXmlBuilder
{
    private const NAMESPACES = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
    ];

    public function build(InvoiceData $data, Profile $profile): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $root = $document->createElementNS(self::NAMESPACES['rsm'], 'rsm:CrossIndustryInvoice');
        foreach (['ram', 'udt'] as $prefix) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, self::NAMESPACES[$prefix]);
        }
        $document->appendChild($root);

        $context = $this->add($document, $root, 'rsm:ExchangedDocumentContext');
        $guideline = $this->add($document, $context, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $this->add($document, $guideline, 'ram:ID', $profile->guidelineIdentifier());

        $exchanged = $this->add($document, $root, 'rsm:ExchangedDocument');
        $this->add($document, $exchanged, 'ram:ID', (string) $data->number);
        $this->add($document, $exchanged, 'ram:TypeCode', '380');
        $this->date($document, $this->add($document, $exchanged, 'ram:IssueDateTime'), $data->issueDate);

        $transaction = $this->add($document, $root, 'rsm:SupplyChainTradeTransaction');

        if ($profile->carriesLines()) {
            foreach ($data->lines as $line) {
                $this->line($document, $transaction, $line, $profile);
            }
        }

        $agreement = $this->add($document, $transaction, 'ram:ApplicableHeaderTradeAgreement');
        $this->party($document, $this->add($document, $agreement, 'ram:SellerTradeParty'), $data->seller, $profile, true);
        $this->party($document, $this->add($document, $agreement, 'ram:BuyerTradeParty'), $data->buyer, $profile, false);

        $delivery = $this->add($document, $transaction, 'ram:ApplicableHeaderTradeDelivery');
        if ($profile->carriesLines() && '' !== $data->deliveryCountryCode) {
            $this->add($document, $this->add($document, $this->add($document, $delivery, 'ram:ShipToTradeParty'), 'ram:PostalTradeAddress'), 'ram:CountryID', $data->deliveryCountryCode);
        }

        $this->settlement($document, $this->add($document, $transaction, 'ram:ApplicableHeaderTradeSettlement'), $data, $profile);

        return (string) $document->saveXML();
    }

    private function line(\DOMDocument $document, \DOMElement $transaction, InvoiceLine $line, Profile $profile): void
    {
        $item = $this->add($document, $transaction, 'ram:IncludedSupplyChainTradeLineItem');
        $this->add($document, $this->add($document, $item, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', (string) $line->position);

        $product = $this->add($document, $item, 'ram:SpecifiedTradeProduct');
        if (Profile::Basic !== $profile && '' !== $line->reference) {
            $this->add($document, $product, 'ram:SellerAssignedID', $line->reference);
        }
        $this->add($document, $product, 'ram:Name', $line->name);

        $agreement = $this->add($document, $item, 'ram:SpecifiedLineTradeAgreement');
        $this->add($document, $this->add($document, $agreement, 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', $line->unitPrice);

        $delivery = $this->add($document, $item, 'ram:SpecifiedLineTradeDelivery');
        $this->add($document, $delivery, 'ram:BilledQuantity', rtrim(rtrim(number_format($line->quantity, 4, '.', ''), '0'), '.'), ['unitCode' => 'C62']);

        $settlement = $this->add($document, $item, 'ram:SpecifiedLineTradeSettlement');
        $tax = $this->add($document, $settlement, 'ram:ApplicableTradeTax');
        $this->add($document, $tax, 'ram:TypeCode', 'VAT');
        $this->add($document, $tax, 'ram:CategoryCode', $line->category->code);
        $this->add($document, $tax, 'ram:RateApplicablePercent', $line->category->rate);
        $this->add($document, $this->add($document, $settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', Cents::format($line->netCents));
    }

    private function party(\DOMDocument $document, \DOMElement $node, InvoiceParty $party, Profile $profile, bool $isSeller): void
    {
        $carriesIdentifiers = $isSeller || $profile->carriesBuyerLegalIdentifiers();
        $siret = $party->siretDigits();

        if ($carriesIdentifiers && '' !== $siret && Profile::Minimum !== $profile) {
            $this->add($document, $node, 'ram:GlobalID', $siret, ['schemeID' => '0009']);
        }

        $this->add($document, $node, 'ram:Name', $party->name);

        if ($carriesIdentifiers && '' !== $party->siren()) {
            $legal = $this->add($document, $node, 'ram:SpecifiedLegalOrganization');
            $this->add($document, $legal, 'ram:ID', $party->siren(), ['schemeID' => '0002']);
        }

        $address = $this->add($document, $node, 'ram:PostalTradeAddress');
        if ($profile->carriesAddresses()) {
            if ('' !== $party->postcode) {
                $this->add($document, $address, 'ram:PostcodeCode', $party->postcode);
            }
            if ('' !== $party->addressLine) {
                $this->add($document, $address, 'ram:LineOne', $party->addressLine);
            }
            if ('' !== $party->city) {
                $this->add($document, $address, 'ram:CityName', $party->city);
            }
        }
        $this->add($document, $address, 'ram:CountryID', $party->countryCode);

        if ('' !== $party->vatNumber && ($isSeller || $profile->carriesBuyerLegalIdentifiers())) {
            $registration = $this->add($document, $node, 'ram:SpecifiedTaxRegistration');
            $this->add($document, $registration, 'ram:ID', $party->vatNumber, ['schemeID' => 'VA']);
        }
    }

    private function settlement(\DOMDocument $document, \DOMElement $node, InvoiceData $data, Profile $profile): void
    {
        $this->add($document, $node, 'ram:InvoiceCurrencyCode', $data->currencyCode);

        if ($profile->carriesPaymentMeans()) {
            $means = $this->add($document, $node, 'ram:SpecifiedTradeSettlementPaymentMeans');
            $this->add($document, $means, 'ram:TypeCode', 'ZZZ');
            if ($profile->carriesPaymentInformation()) {
                $this->add($document, $means, 'ram:Information', $data->paymentTitle);
            }
        }

        if ($profile->carriesTaxBreakdown()) {
            foreach ($data->taxBreakdown as $entry) {
                $this->taxEntry($document, $node, $entry);
            }
        }

        if ($profile->carriesAllowancesAndCharges()) {
            foreach ($data->adjustments as $adjustment) {
                $this->adjustment($document, $node, $adjustment);
            }
        }

        if ($profile->carriesPaymentMeans() && $data->dueCents() > 0 && null !== $data->issueDate) {
            $terms = $this->add($document, $node, 'ram:SpecifiedTradePaymentTerms');
            $this->date($document, $this->add($document, $terms, 'ram:DueDateDateTime'), $data->issueDate);
        }

        $this->summation($document, $this->add($document, $node, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation'), $data, $profile);
    }

    private function taxEntry(\DOMDocument $document, \DOMElement $node, TaxBreakdownEntry $entry): void
    {
        $tax = $this->add($document, $node, 'ram:ApplicableTradeTax');
        $this->add($document, $tax, 'ram:CalculatedAmount', Cents::format($entry->taxCents));
        $this->add($document, $tax, 'ram:TypeCode', 'VAT');
        if (null !== $entry->category->exemptionReason) {
            $this->add($document, $tax, 'ram:ExemptionReason', $entry->category->exemptionReason);
        }
        $this->add($document, $tax, 'ram:BasisAmount', Cents::format($entry->basisCents));
        $this->add($document, $tax, 'ram:CategoryCode', $entry->category->code);
        if (null !== $entry->category->exemptionReasonCode) {
            $this->add($document, $tax, 'ram:ExemptionReasonCode', $entry->category->exemptionReasonCode);
        }
        $this->add($document, $tax, 'ram:RateApplicablePercent', $entry->category->rate);
    }

    private function adjustment(\DOMDocument $document, \DOMElement $node, Adjustment $adjustment): void
    {
        $charge = $this->add($document, $node, 'ram:SpecifiedTradeAllowanceCharge');
        $indicator = $this->add($document, $charge, 'ram:ChargeIndicator');
        $this->add($document, $indicator, 'udt:Indicator', $adjustment->isCharge ? 'true' : 'false');
        $this->add($document, $charge, 'ram:ActualAmount', Cents::format($adjustment->cents));
        $this->add($document, $charge, 'ram:Reason', $adjustment->reason);
        $tax = $this->add($document, $charge, 'ram:CategoryTradeTax');
        $this->add($document, $tax, 'ram:TypeCode', 'VAT');
        $this->add($document, $tax, 'ram:CategoryCode', $adjustment->category->code);
        $this->add($document, $tax, 'ram:RateApplicablePercent', $adjustment->category->rate);
    }

    private function summation(\DOMDocument $document, \DOMElement $node, InvoiceData $data, Profile $profile): void
    {
        $detailed = Profile::Minimum !== $profile;

        if ($detailed) {
            $this->add($document, $node, 'ram:LineTotalAmount', Cents::format($data->lineTotalCents));
            if ($data->chargeTotalCents > 0) {
                $this->add($document, $node, 'ram:ChargeTotalAmount', Cents::format($data->chargeTotalCents));
            }
            if ($data->allowanceTotalCents > 0) {
                $this->add($document, $node, 'ram:AllowanceTotalAmount', Cents::format($data->allowanceTotalCents));
            }
        }

        $this->add($document, $node, 'ram:TaxBasisTotalAmount', Cents::format($data->taxBasisTotalCents));
        $this->add($document, $node, 'ram:TaxTotalAmount', Cents::format($data->taxTotalCents), ['currencyID' => $data->currencyCode]);
        $this->add($document, $node, 'ram:GrandTotalAmount', Cents::format($data->grandTotalCents));
        $prepaid = $detailed ? $data->prepaidCents : 0;
        if ($prepaid > 0) {
            $this->add($document, $node, 'ram:TotalPrepaidAmount', Cents::format($prepaid));
        }
        $this->add($document, $node, 'ram:DuePayableAmount', Cents::format($data->grandTotalCents - $prepaid));
    }

    private function date(\DOMDocument $document, \DOMElement $node, ?\DateTimeInterface $date): void
    {
        $this->add($document, $node, 'udt:DateTimeString', $date?->format('Ymd') ?? '', ['format' => '102']);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function add(\DOMDocument $document, \DOMElement $parent, string $name, ?string $value = null, array $attributes = []): \DOMElement
    {
        [$prefix] = explode(':', $name);
        $element = $document->createElementNS(self::NAMESPACES[$prefix], $name);

        if (null !== $value) {
            $element->appendChild($document->createTextNode($value));
        }

        foreach ($attributes as $attribute => $attributeValue) {
            $element->setAttribute($attribute, $attributeValue);
        }

        $parent->appendChild($element);

        return $element;
    }
}
