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

final readonly class CompletenessReport
{
    /**
     * @param list<string> $missing
     */
    public function __construct(
        public Profile $profile,
        public array $missing,
    ) {
    }

    public function isComplete(): bool
    {
        return [] === $this->missing;
    }
}
