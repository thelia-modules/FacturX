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

use CreditNote\Model\CreditNote;
use CreditNote\Model\CreditNoteAddress;
use CreditNote\Model\CreditNoteDetail;
use CreditNote\Model\CreditNoteStatusQuery;
use CreditNote\Model\CreditNoteTypeQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;

/**
 * A saved, accepted credit note refunding the first line of an order, written the way the
 * back-office of the CreditNote module writes it. Kept here rather than borrowed from the
 * test support of that module, which a shop does not install.
 */
final readonly class CreditNoteBuilder
{
    public function __construct(
        private FixtureFactory $factory,
        private ConnectionInterface $connection,
    ) {
    }

    public function accepted(Customer $customer, Order $order): CreditNote
    {
        $address = (new CreditNoteAddress())
            ->setCustomerTitleId($customer->getTitleId())
            ->setFirstname($customer->getFirstname())
            ->setLastname($customer->getLastname())
            ->setAddress1('3 rue de Paris')
            ->setZipcode('93100')
            ->setCity('Montreuil')
            ->setCountryId($this->factory->country()->getId());
        $address->save($this->connection);

        $orderProduct = $order->getOrderProducts()->getFirst();

        $creditNote = (new CreditNote())
            ->setCreditNoteAddress($address)
            ->setCustomerId($customer->getId())
            ->setOrderId($order->getId())
            ->setTypeId(CreditNoteTypeQuery::create()->findOneByCode('back_product')?->getId() ?? throw new \RuntimeException('The credit note type "back_product" is not seeded.'))
            ->setStatusId(CreditNoteStatusQuery::create()->findOneByCode('accepted')?->getId() ?? throw new \RuntimeException('The credit note status "accepted" is not seeded.'))
            ->setCurrencyId($order->getCurrencyId())
            ->setCurrencyRate(1.0)
            ->setDiscountWithoutTax('0')
            ->setDiscountWithTax('0')
            ->setAllowPartialUse(true)
            ->setTotalPrice('100.000000')
            ->setTotalPriceWithTax('120.000000');
        $creditNote->addCreditNoteDetail((new CreditNoteDetail())
            ->setOrderProductId($orderProduct->getId())
            ->setTitle($orderProduct->getTitle())
            ->setType('product')
            ->setQuantity(1)
            ->setPrice('100.000000')
            ->setPriceWithTax('120.000000'));
        $creditNote->save($this->connection);

        return $creditNote;
    }
}
