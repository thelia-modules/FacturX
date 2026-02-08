<?php

declare(strict_types=1);

namespace FacturX\Service;

use Atgp\FacturX\Writer;
use FacturX\FacturX;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;

final readonly class FacturXService
{
    public function isEnabled(): bool
    {
        return '1' === FacturX::getConfigValue(FacturX::CONFIG_IS_ENABLED, '0');
    }

    public function generateFacturXPdf(string $pdfContent, Order $order): string
    {
        $xml = $this->buildXmlCii($order);

        $writer = new Writer();

        return $writer->generate(
            $pdfContent,
            $xml,
            'en16931',
            false,
        );
    }

    public function archivePdf(string $pdfContent, Order $order): string
    {
        $storagePath = FacturX::getConfigValue(
            FacturX::CONFIG_STORAGE_PATH,
            THELIA_LOCAL_DIR . '/media/documents/facturx/'
        );

        $year = date('Y', $order->getInvoiceDate() ? $order->getInvoiceDate()->getTimestamp() : $order->getCreatedAt()->getTimestamp());
        $ref = $order->getInvoiceRef() ?: $order->getRef();
        $directory = rtrim($storagePath, '/') . '/' . $year;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $filePath = $directory . '/' . $ref . '.pdf';
        file_put_contents($filePath, $pdfContent);

        return $filePath;
    }

    private function buildXmlCii(Order $order): string
    {
        $invoiceRef = $order->getInvoiceRef() ?: $order->getRef();
        $invoiceDate = $order->getInvoiceDate() ?? $order->getCreatedAt();
        $formattedDate = $invoiceDate->format('Ymd');

        $storeName = ConfigQuery::getStoreName();
        $storeAddress1 = ConfigQuery::read('store_address1', '');
        $storeZipcode = ConfigQuery::read('store_zipcode', '');
        $storeCity = ConfigQuery::read('store_city', '');
        $storeCountryId = ConfigQuery::getStoreCountry();
        $storeCountryCode = 'FR';
        if ($storeCountryId) {
            $country = CountryQuery::create()->findPk($storeCountryId);
            if ($country) {
                $storeCountryCode = $country->getIsoalpha2() ?: 'FR';
            }
        }

        $siret = FacturX::getConfigValue(FacturX::CONFIG_SIRET, '');
        $tvaIntracom = FacturX::getConfigValue(FacturX::CONFIG_TVA_INTRACOM, '');

        $invoiceAddress = $order->getOrderAddressRelatedByInvoiceOrderAddressId();
        $buyerName = trim($invoiceAddress->getFirstname() . ' ' . $invoiceAddress->getLastname());
        if ($invoiceAddress->getCompany()) {
            $buyerName = $invoiceAddress->getCompany();
        }
        $buyerAddress = $invoiceAddress->getAddress1();
        $buyerZipcode = $invoiceAddress->getZipcode();
        $buyerCity = $invoiceAddress->getCity();
        $buyerCountryCode = 'FR';
        $buyerCountry = CountryQuery::create()->findPk($invoiceAddress->getCountryId());
        if ($buyerCountry) {
            $buyerCountryCode = $buyerCountry->getIsoalpha2() ?: 'FR';
        }

        $currency = $order->getCurrency();
        $currencyCode = $currency ? $currency->getCode() : 'EUR';

        $tax = 0.0;
        $totalTTC = $order->getTotalAmount($tax, true, true);
        $totalHT = $totalTTC - $tax;

        $orderProducts = $order->getOrderProducts();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"';
        $xml .= ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"';
        $xml .= ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"';
        $xml .= ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">';

        // ExchangedDocumentContext
        $xml .= '<rsm:ExchangedDocumentContext>';
        $xml .= '<ram:GuidelineSpecifiedDocumentContextParameter>';
        $xml .= '<ram:ID>urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931</ram:ID>';
        $xml .= '</ram:GuidelineSpecifiedDocumentContextParameter>';
        $xml .= '</rsm:ExchangedDocumentContext>';

        // ExchangedDocument
        $xml .= '<rsm:ExchangedDocument>';
        $xml .= '<ram:ID>' . $this->xmlEscape($invoiceRef) . '</ram:ID>';
        $xml .= '<ram:TypeCode>380</ram:TypeCode>'; // 380 = Facture commerciale
        $xml .= '<ram:IssueDateTime>';
        $xml .= '<udt:DateTimeString format="102">' . $formattedDate . '</udt:DateTimeString>';
        $xml .= '</ram:IssueDateTime>';
        $xml .= '</rsm:ExchangedDocument>';

        // SupplyChainTradeTransaction
        $xml .= '<rsm:SupplyChainTradeTransaction>';

        // Lignes de facture
        $lineNumber = 0;
        foreach ($orderProducts as $orderProduct) {
            /** @var OrderProduct $orderProduct */
            $lineNumber++;
            $xml .= $this->buildInvoiceLine($orderProduct, $lineNumber, $currencyCode);
        }

        // ApplicableHeaderTradeAgreement (vendeur / acheteur)
        $xml .= '<ram:ApplicableHeaderTradeAgreement>';

        // Seller
        $xml .= '<ram:SellerTradeParty>';
        $xml .= '<ram:Name>' . $this->xmlEscape($storeName) . '</ram:Name>';
        if ($siret) {
            $xml .= '<ram:SpecifiedLegalOrganization>';
            $xml .= '<ram:ID schemeID="0002">' . $this->xmlEscape($siret) . '</ram:ID>';
            $xml .= '</ram:SpecifiedLegalOrganization>';
        }
        $xml .= '<ram:PostalTradeAddress>';
        $xml .= '<ram:LineOne>' . $this->xmlEscape($storeAddress1) . '</ram:LineOne>';
        $xml .= '<ram:PostcodeCode>' . $this->xmlEscape($storeZipcode) . '</ram:PostcodeCode>';
        $xml .= '<ram:CityName>' . $this->xmlEscape($storeCity) . '</ram:CityName>';
        $xml .= '<ram:CountryID>' . $storeCountryCode . '</ram:CountryID>';
        $xml .= '</ram:PostalTradeAddress>';
        if ($tvaIntracom) {
            $xml .= '<ram:SpecifiedTaxRegistration>';
            $xml .= '<ram:ID schemeID="VA">' . $this->xmlEscape($tvaIntracom) . '</ram:ID>';
            $xml .= '</ram:SpecifiedTaxRegistration>';
        }
        $xml .= '</ram:SellerTradeParty>';

        // Buyer
        $xml .= '<ram:BuyerTradeParty>';
        $xml .= '<ram:Name>' . $this->xmlEscape($buyerName) . '</ram:Name>';
        $xml .= '<ram:PostalTradeAddress>';
        $xml .= '<ram:LineOne>' . $this->xmlEscape($buyerAddress) . '</ram:LineOne>';
        $xml .= '<ram:PostcodeCode>' . $this->xmlEscape($buyerZipcode) . '</ram:PostcodeCode>';
        $xml .= '<ram:CityName>' . $this->xmlEscape($buyerCity) . '</ram:CityName>';
        $xml .= '<ram:CountryID>' . $buyerCountryCode . '</ram:CountryID>';
        $xml .= '</ram:PostalTradeAddress>';
        $xml .= '</ram:BuyerTradeParty>';

        $xml .= '</ram:ApplicableHeaderTradeAgreement>';

        // ApplicableHeaderTradeDelivery
        $xml .= '<ram:ApplicableHeaderTradeDelivery/>';

        // ApplicableHeaderTradeSettlement
        $xml .= '<ram:ApplicableHeaderTradeSettlement>';
        $xml .= '<ram:InvoiceCurrencyCode>' . $currencyCode . '</ram:InvoiceCurrencyCode>';

        // Tax summary
        $xml .= '<ram:ApplicableTradeTax>';
        $xml .= '<ram:CalculatedAmount>' . $this->formatAmount($tax) . '</ram:CalculatedAmount>';
        $xml .= '<ram:TypeCode>VAT</ram:TypeCode>';
        $xml .= '<ram:BasisAmount>' . $this->formatAmount($totalHT) . '</ram:BasisAmount>';
        $xml .= '<ram:CategoryCode>S</ram:CategoryCode>';
        $xml .= '<ram:RateApplicablePercent>' . $this->formatAmount($this->computeGlobalTaxRate($order)) . '</ram:RateApplicablePercent>';
        $xml .= '</ram:ApplicableTradeTax>';

        // Monetary summation
        $xml .= '<ram:SpecifiedTradeSettlementHeaderMonetarySummation>';
        $xml .= '<ram:LineTotalAmount>' . $this->formatAmount($this->computeLineTotalHT($order)) . '</ram:LineTotalAmount>';
        $xml .= '<ram:TaxBasisTotalAmount>' . $this->formatAmount($totalHT) . '</ram:TaxBasisTotalAmount>';
        $xml .= '<ram:TaxTotalAmount currencyID="' . $currencyCode . '">' . $this->formatAmount($tax) . '</ram:TaxTotalAmount>';
        $xml .= '<ram:GrandTotalAmount>' . $this->formatAmount($totalTTC) . '</ram:GrandTotalAmount>';
        $xml .= '<ram:DuePayableAmount>' . $this->formatAmount($totalTTC) . '</ram:DuePayableAmount>';
        $xml .= '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>';

        $xml .= '</ram:ApplicableHeaderTradeSettlement>';

        $xml .= '</rsm:SupplyChainTradeTransaction>';
        $xml .= '</rsm:CrossIndustryInvoice>';

        return $xml;
    }

    private function buildInvoiceLine(OrderProduct $orderProduct, int $lineNumber, string $currencyCode): string
    {
        $quantity = $orderProduct->getQuantity();
        $unitPrice = $orderProduct->getWasInPromo() ? (float) $orderProduct->getPromoPrice() : (float) $orderProduct->getPrice();
        $lineTotal = round($quantity * $unitPrice, 2);

        $lineTax = 0.0;
        foreach ($orderProduct->getOrderProductTaxes() as $orderProductTax) {
            $taxAmount = $orderProduct->getWasInPromo()
                ? (float) $orderProductTax->getPromoAmount()
                : (float) $orderProductTax->getAmount();
            $lineTax += $taxAmount;
        }
        $taxRate = $unitPrice > 0 ? round(($lineTax / $unitPrice) * 100, 2) : 0.0;

        $xml = '<ram:IncludedSupplyChainTradeLineItem>';

        $xml .= '<ram:AssociatedDocumentLineDocument>';
        $xml .= '<ram:LineID>' . $lineNumber . '</ram:LineID>';
        $xml .= '</ram:AssociatedDocumentLineDocument>';

        $xml .= '<ram:SpecifiedTradeProduct>';
        $xml .= '<ram:Name>' . $this->xmlEscape($orderProduct->getTitle()) . '</ram:Name>';
        $xml .= '</ram:SpecifiedTradeProduct>';

        $xml .= '<ram:SpecifiedLineTradeAgreement>';
        $xml .= '<ram:NetPriceProductTradePrice>';
        $xml .= '<ram:ChargeAmount>' . $this->formatAmount($unitPrice) . '</ram:ChargeAmount>';
        $xml .= '</ram:NetPriceProductTradePrice>';
        $xml .= '</ram:SpecifiedLineTradeAgreement>';

        $xml .= '<ram:SpecifiedLineTradeDelivery>';
        $xml .= '<ram:BilledQuantity unitCode="C62">' . $this->formatAmount($quantity) . '</ram:BilledQuantity>';
        $xml .= '</ram:SpecifiedLineTradeDelivery>';

        $xml .= '<ram:SpecifiedLineTradeSettlement>';
        $xml .= '<ram:ApplicableTradeTax>';
        $xml .= '<ram:TypeCode>VAT</ram:TypeCode>';
        $xml .= '<ram:CategoryCode>S</ram:CategoryCode>';
        $xml .= '<ram:RateApplicablePercent>' . $this->formatAmount($taxRate) . '</ram:RateApplicablePercent>';
        $xml .= '</ram:ApplicableTradeTax>';
        $xml .= '<ram:SpecifiedTradeSettlementLineMonetarySummation>';
        $xml .= '<ram:LineTotalAmount>' . $this->formatAmount($lineTotal) . '</ram:LineTotalAmount>';
        $xml .= '</ram:SpecifiedTradeSettlementLineMonetarySummation>';
        $xml .= '</ram:SpecifiedLineTradeSettlement>';

        $xml .= '</ram:IncludedSupplyChainTradeLineItem>';

        return $xml;
    }

    private function computeLineTotalHT(Order $order): float
    {
        $total = 0.0;
        foreach ($order->getOrderProducts() as $orderProduct) {
            $unitPrice = $orderProduct->getWasInPromo() ? (float) $orderProduct->getPromoPrice() : (float) $orderProduct->getPrice();
            $total += round($orderProduct->getQuantity() * $unitPrice, 2);
        }

        return $total;
    }

    private function computeGlobalTaxRate(Order $order): float
    {
        $tax = 0.0;
        $order->getTotalAmount($tax, false, true);
        $totalHT = $this->computeLineTotalHT($order) - (float) $order->getDiscount() + $this->computeDiscountTax($order);

        if ($totalHT <= 0) {
            return 0.0;
        }

        return round(($tax / $totalHT) * 100, 2);
    }

    private function computeDiscountTax(Order $order): float
    {
        $discount = (float) $order->getDiscount();
        if ($discount <= 0) {
            return 0.0;
        }

        $tax = 0.0;
        $totalTTC = $order->getTotalAmount($tax, false, false);
        $totalHT = $totalTTC - $tax;

        if ($totalHT <= 0) {
            return 0.0;
        }

        $rate = $tax / $totalHT;

        return round($discount * $rate / (1 + $rate), 2);
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
