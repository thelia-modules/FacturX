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

final class IncompleteInvoiceException extends \RuntimeException
{
    public function __construct(public readonly CompletenessReport $report)
    {
        parent::__construct(\sprintf('The Factur-X invoice cannot be issued, missing: %s', implode(', ', $report->missing)));
    }
}
