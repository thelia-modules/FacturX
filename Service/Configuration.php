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

use FacturX\Domain\Profile;
use FacturX\FacturX;

readonly class Configuration
{
    public function isEnabled(): bool
    {
        return '1' === FacturX::getConfigValue(FacturX::CONFIG_IS_ENABLED, '0');
    }

    public function profile(): Profile
    {
        return Profile::fromConfiguration(FacturX::getConfigValue(FacturX::CONFIG_PROFILE));
    }

    public function sendsInvoiceMail(): bool
    {
        return '1' === FacturX::getConfigValue(FacturX::CONFIG_SEND_MAIL, '0');
    }

    public function requiresBuyerVatNumber(): bool
    {
        return '1' === FacturX::getConfigValue(FacturX::CONFIG_REQUIRE_BUYER_VAT, '0');
    }

    public function fallbackSiret(): string
    {
        return (string) FacturX::getConfigValue(FacturX::CONFIG_SIRET, '');
    }

    public function fallbackVatNumber(): string
    {
        return (string) FacturX::getConfigValue(FacturX::CONFIG_TVA_INTRACOM, '');
    }

    public function storagePath(): string
    {
        return rtrim((string) FacturX::getConfigValue(FacturX::CONFIG_STORAGE_PATH, THELIA_LOCAL_DIR.'media/documents/facturx/'), '/');
    }
}
