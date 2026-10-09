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

enum Profile: string
{
    case Minimum = 'minimum';
    case BasicWithoutLines = 'basicwl';
    case Basic = 'basic';
    case En16931 = 'en16931';
    case Extended = 'extended';

    public const DEFAULT = self::En16931;

    public static function fromConfiguration(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DEFAULT;
    }

    public function guidelineIdentifier(): string
    {
        return match ($this) {
            self::Minimum => 'urn:factur-x.eu:1p0:minimum',
            self::BasicWithoutLines => 'urn:factur-x.eu:1p0:basicwl',
            self::Basic => 'urn:factur-x.eu:1p0:basic',
            self::En16931 => 'urn:cen.eu:en16931:2017',
            self::Extended => 'urn:factur-x.eu:1p0:extended',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Minimum => 'Minimum',
            self::BasicWithoutLines => 'Basic WL',
            self::Basic => 'Basic',
            self::En16931 => 'EN 16931',
            self::Extended => 'Extended',
        };
    }

    public function carriesLines(): bool
    {
        return match ($this) {
            self::Basic, self::En16931, self::Extended => true,
            default => false,
        };
    }

    public function carriesTaxBreakdown(): bool
    {
        return self::Minimum !== $this;
    }

    public function carriesAllowancesAndCharges(): bool
    {
        return self::Minimum !== $this;
    }

    public function carriesPaymentMeans(): bool
    {
        return self::Minimum !== $this;
    }

    public function carriesPaymentInformation(): bool
    {
        return self::En16931 === $this || self::Extended === $this;
    }

    public function carriesAddresses(): bool
    {
        return self::Minimum !== $this;
    }

    public function carriesBuyerLegalIdentifiers(): bool
    {
        return self::Minimum !== $this;
    }
}
