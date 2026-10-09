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

use FacturX\Domain\Profile;
use FacturX\Service\Configuration;

readonly class StubConfiguration extends Configuration
{
    public function __construct(
        private string $storage,
        private Profile $selectedProfile = Profile::En16931,
        private bool $enabled = true,
        private bool $mail = false,
        private bool $buyerVat = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function profile(): Profile
    {
        return $this->selectedProfile;
    }

    public function sendsInvoiceMail(): bool
    {
        return $this->mail;
    }

    public function requiresBuyerVatNumber(): bool
    {
        return $this->buyerVat;
    }

    public function fallbackSiret(): string
    {
        return '';
    }

    public function fallbackVatNumber(): string
    {
        return '';
    }

    public function storagePath(): string
    {
        return $this->storage;
    }
}
