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
use FacturX\Service\FacturXGenerator;
use FacturX\Service\GenerationPermit;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;

final readonly class InvoicePdfListener
{
    public function __construct(
        private Configuration $configuration,
        private FacturXGenerator $generator,
        private InvoiceArchive $archive,
        private InvoiceNumberResolver $numberResolver,
        private GenerationPermit $permit,
    ) {
    }

    #[AsEventListener(event: TheliaEvents::GENERATE_PDF, priority: 64)]
    public function onGeneratePdf(PdfEvent $event): void
    {
        if (!$this->configuration->isEnabled() || !$event->hasPdf()) {
            return;
        }

        $order = $event->getObject();
        if (!$order instanceof Order) {
            return;
        }

        if ($event->getTemplateName() !== ConfigQuery::read('pdf_invoice_file', 'invoice')) {
            return;
        }

        $number = $this->numberResolver->resolve($order);
        $issueDate = $order->getInvoiceDate();
        if (null === $number || null === $issueDate) {
            return;
        }

        $archived = $this->archive->find($number, $issueDate);
        if (null !== $archived) {
            $event->setPdf($archived);

            return;
        }

        if (!$this->permit->isOpen() && !$this->permit->isPreview()) {
            return;
        }

        $facturX = $this->generator->generate($order, (string) $event->getPdf());
        if ($this->permit->isOpen()) {
            $this->archive->store($number, $issueDate, $facturX, $this->configuration->profile());
        }
        $event->setPdf($facturX);
    }
}
