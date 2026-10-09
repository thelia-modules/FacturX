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

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;

final class OrderBuilder
{
    public function __construct(
        private readonly FixtureFactory $factory,
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * @param list<array{price: string, quantity: int, taxes: list<string>, title?: string}> $lines
     */
    public function paid(array $lines, string $postage = '0', string $postageTax = '0', string $discount = '0', ?Customer $customer = null): Order
    {
        $order = $this->factory->order($customer, [
            'statusCode' => OrderStatus::CODE_PAID,
            'postage' => $postage,
            'postageTax' => $postageTax,
        ]);

        foreach ($lines as $index => $line) {
            $orderProduct = (new OrderProduct())
                ->setOrderId($order->getId())
                ->setProductRef('REF-'.$index)
                ->setProductSaleElementsRef('PSE-'.$index)
                ->setTitle($line['title'] ?? 'Product '.$index)
                ->setQuantity((float) $line['quantity'])
                ->setPrice($line['price'])
                ->setWasNew(0)
                ->setWasInPromo(0);
            $orderProduct->save($this->connection);

            foreach ($line['taxes'] as $taxIndex => $amount) {
                (new OrderProductTax())
                    ->setOrderProductId($orderProduct->getId())
                    ->setTitle('Tax '.$taxIndex)
                    ->setAmount($amount)
                    ->setPromoAmount($amount)
                    ->save($this->connection);
            }
        }

        if ('0' !== $discount) {
            $order->setDiscount($discount)->save($this->connection);
        }

        return $order;
    }
}
