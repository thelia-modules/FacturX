<?php

declare(strict_types=1);

namespace FacturX\EventListener;

use FacturX\Service\FacturXService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;

final readonly class InvoicePdfListener
{
    public function __construct(
        private FacturXService $facturXService,
    ) {
    }

    #[AsEventListener(event: TheliaEvents::GENERATE_PDF, priority: 64)]
    public function onGeneratePdf(PdfEvent $event): void
    {
        if (!$this->facturXService->isEnabled()) {
            return;
        }

        if (!$event->hasPdf()) {
            return;
        }

        $object = $event->getObject();
        if (!$object instanceof Order) {
            return;
        }

        $expectedTemplateName = ConfigQuery::read('pdf_invoice_file', 'invoice');
        if ($event->getTemplateName() !== $expectedTemplateName) {
            return;
        }

        try {
            $facturxPdf = $this->facturXService->generateFacturXPdf($event->getPdf(), $object);
            $this->facturXService->archivePdf($facturxPdf, $object);
            $event->setPdf($facturxPdf);
        } catch (\Exception $e) {
            Tlog::getInstance()->error(
                sprintf('FacturX: erreur lors de la génération Factur-X pour la commande %s : %s', $object->getRef(), $e->getMessage())
            );
        }
    }
}
