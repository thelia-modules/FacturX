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

namespace FacturX\EventListener;

use FacturX\Service\Configuration;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Service\InvoiceRenderer;
use FacturX\Service\RenderingRequest;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;

final readonly class InvoiceMailListener
{
    public const MESSAGE_CODE = 'facturx_order_invoice';

    public function __construct(
        private Configuration $configuration,
        private InvoiceNumberResolver $numberResolver,
        private InvoiceArchive $archive,
        private InvoiceRenderer $renderer,
        private MailerFactory $mailer,
        private RenderingRequest $renderingRequest,
    ) {
    }

    #[AsEventListener(event: TheliaEvents::ORDER_UPDATE_STATUS, priority: 32)]
    public function onOrderStatusUpdate(OrderEvent $event): void
    {
        if (!$this->configuration->isEnabled() || !$this->configuration->sendsInvoiceMail()) {
            return;
        }

        $order = $event->getOrder();
        if (!$order->isPaid(false)) {
            return;
        }

        $number = $this->numberResolver->resolve($order);
        $issueDate = $order->getInvoiceDate();
        $customer = $order->getCustomer();
        if (null === $number || null === $issueDate || '' === (string) $customer->getEmail()) {
            return;
        }

        if ($this->archive->wasMailed($number, $issueDate)) {
            return;
        }

        $this->renderingRequest->during($order->getLang(), fn () => $this->mail($order, $number, $issueDate, $customer));
    }

    private function mail(Order $order, string $number, \DateTimeInterface $issueDate, Customer $customer): void
    {
        try {
            $pdf = $this->renderer->render($order);
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->error(\sprintf('FacturX: the invoice of order %s was not mailed: %s', $order->getRef(), $throwable->getMessage()));

            return;
        }

        $locale = $order->getLang()->getLocale();

        $email = $this->mailer->createEmailMessage(
            self::MESSAGE_CODE,
            [ConfigQuery::getStoreEmail() => ConfigQuery::getStoreName()],
            [$customer->getEmail() => trim($customer->getFirstname().' '.$customer->getLastname())],
            [
                'order_id' => $order->getId(),
                'order_ref' => $order->getRef(),
                'invoice_ref' => $number,
                'invoice_locale' => $locale,
            ],
            $locale,
        );
        $email->attach($pdf, \sprintf('invoice-%s.pdf', preg_replace('/[^A-Za-z0-9._-]/', '_', $number)), 'application/pdf');

        $this->mailer->send($email);
        $this->archive->markMailed($number, $issueDate);
    }
}
