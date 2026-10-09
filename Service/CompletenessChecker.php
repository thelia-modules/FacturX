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

use FacturX\Domain\CompletenessReport;
use FacturX\Domain\InvoiceData;
use FacturX\Domain\Profile;

final readonly class CompletenessChecker
{
    public const NOT_INVOICED = 'not_invoiced';
    public const LEGACY_ROUNDING = 'legacy_rounding';
    public const INCONSISTENT_TOTALS = 'inconsistent_totals';
    public const CURRENCY = 'currency';
    public const SELLER_NAME = 'seller_name';
    public const SELLER_COUNTRY = 'seller_country';
    public const SELLER_ADDRESS = 'seller_address';
    public const SELLER_IDENTIFIER = 'seller_identifier';
    public const SELLER_VAT_NUMBER = 'seller_vat_number';
    public const BUYER_NAME = 'buyer_name';
    public const BUYER_COUNTRY = 'buyer_country';
    public const BUYER_ADDRESS = 'buyer_address';
    public const BUYER_VAT_NUMBER = 'buyer_vat_number';
    public const PAYMENT_MEANS = 'payment_means';
    public const NO_LINES = 'no_lines';

    public function check(InvoiceData $data, Profile $profile, bool $requireBuyerVat = false): CompletenessReport
    {
        $missing = [];

        if (null === $data->issueDate || null === $data->number) {
            $missing[] = self::NOT_INVOICED;
        }
        if ($data->isLegacyRounding) {
            $missing[] = self::LEGACY_ROUNDING;
        }
        if ([] !== $data->inconsistencies) {
            $missing[] = self::INCONSISTENT_TOTALS;
        }
        if ('' === $data->currencyCode) {
            $missing[] = self::CURRENCY;
        }

        array_push($missing, ...$this->sellerGaps($data, $profile), ...$this->buyerGaps($data, $profile, $requireBuyerVat));

        if ($profile->carriesPaymentMeans() && '' === trim($data->paymentTitle)) {
            $missing[] = self::PAYMENT_MEANS;
        }
        if ($profile->carriesLines() && [] === $data->lines) {
            $missing[] = self::NO_LINES;
        }

        return new CompletenessReport($profile, array_values(array_unique($missing)));
    }

    /**
     * @return list<string>
     */
    private function sellerGaps(InvoiceData $data, Profile $profile): array
    {
        $seller = $data->seller;
        $gaps = [];

        if ('' === trim($seller->name)) {
            $gaps[] = self::SELLER_NAME;
        }
        if ('' === $seller->countryCode) {
            $gaps[] = self::SELLER_COUNTRY;
        }
        if ($profile->carriesAddresses() && ('' === trim($seller->addressLine) || '' === trim($seller->postcode) || '' === trim($seller->city))) {
            $gaps[] = self::SELLER_ADDRESS;
        }
        if ('' === $seller->siren() && '' === $seller->vatNumber) {
            $gaps[] = self::SELLER_IDENTIFIER;
        }
        if ('' === $seller->vatNumber && ($data->hasCategory('S') || $data->hasCategory('K'))) {
            $gaps[] = self::SELLER_VAT_NUMBER;
        }

        return $gaps;
    }

    /**
     * @return list<string>
     */
    private function buyerGaps(InvoiceData $data, Profile $profile, bool $requireBuyerVat): array
    {
        $buyer = $data->buyer;
        $gaps = [];

        if ('' === trim($buyer->name)) {
            $gaps[] = self::BUYER_NAME;
        }
        if ('' === $buyer->countryCode) {
            $gaps[] = self::BUYER_COUNTRY;
        }
        if ($profile->carriesAddresses() && '' === trim($buyer->addressLine)) {
            $gaps[] = self::BUYER_ADDRESS;
        }

        $vatRequired = $data->hasCategory('K')
            || ($requireBuyerVat && $buyer->isCompany);
        if ($vatRequired && '' === $buyer->vatNumber) {
            $gaps[] = self::BUYER_VAT_NUMBER;
        }

        return $gaps;
    }
}
