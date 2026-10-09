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

final class GenerationPermit
{
    private int $depth = 0;
    private bool $preview = false;

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function beginPreview(): void
    {
        $this->preview = true;
    }

    public function endPreview(): void
    {
        $this->preview = false;
    }

    public function during(callable $generation): mixed
    {
        ++$this->depth;

        try {
            return $generation();
        } finally {
            --$this->depth;
        }
    }
}
