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

return [
    'facturx.missing.not_invoiced' => 'The order has no invoice date yet: it is not paid.',
    'facturx.missing.legacy_rounding' => 'The order predates the current rounding rules and cannot be written as a structured invoice.',
    'facturx.missing.inconsistent_totals' => 'The totals computed from the order lines differ from the totals of the order.',
    'facturx.missing.currency' => 'The currency of the order is unknown.',
    'facturx.missing.seller_name' => 'The store name is missing in the store settings.',
    'facturx.missing.seller_country' => 'The store country is missing in the store settings.',
    'facturx.missing.seller_address' => 'The store address, zip code or city is missing in the store settings.',
    'facturx.missing.seller_identifier' => 'The store has neither a SIRET nor an intra-community VAT number.',
    'facturx.missing.seller_vat_number' => 'The store intra-community VAT number is missing: the invoice carries VAT.',
    'facturx.missing.buyer_name' => 'The invoice address has no name or company.',
    'facturx.missing.buyer_country' => 'The invoice address has no country.',
    'facturx.missing.buyer_address' => 'The invoice address has no street.',
    'facturx.missing.buyer_vat_number' => 'The buyer VAT number is missing.',
    'facturx.missing.payment_means' => 'The order has no payment method.',
    'facturx.missing.no_lines' => 'The order has no line.',
];
