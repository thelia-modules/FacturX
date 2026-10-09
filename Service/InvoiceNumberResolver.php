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

use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;

final readonly class InvoiceNumberResolver
{
    public const CONFIG_SINCE = 'pdf_invoice_ref_from';

    public function resolve(Order $order): ?string
    {
        $issueDate = $order->getInvoiceDate();

        if (null === $issueDate) {
            return null;
        }

        $invoiceRef = (string) $order->getInvoiceRef();
        $since = (string) ConfigQuery::read(self::CONFIG_SINCE, '');

        if ('' === $invoiceRef || ('' !== $since && $issueDate->format('Y-m-d') < $since)) {
            return 'FA'.substr($order->getRef(), 3);
        }

        return $invoiceRef;
    }
}
