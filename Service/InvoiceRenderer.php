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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;

readonly class InvoiceRenderer
{
    public function __construct(
        private ParserResolver $parserResolver,
        private TemplateHelperInterface $templateHelper,
        private EventDispatcherInterface $eventDispatcher,
        private GenerationPermit $permit,
    ) {
    }

    public function render(Order $order): string
    {
        $fileName = (string) ConfigQuery::read('pdf_invoice_file', 'invoice');

        $pdfTemplate = $this->templateHelper->getActivePdfTemplate();
        $parser = $this->parserResolver->getParser($pdfTemplate->getAbsolutePath(), $fileName);
        $parser->setTemplateDefinition($pdfTemplate, true);

        $event = new PdfEvent($parser->render($fileName, ['order_id' => $order->getId()]));
        $event->setTemplateName($fileName);
        $event->setFileName($order->getRef());
        $event->setObject($order);

        $this->permit->during(fn () => $this->eventDispatcher->dispatch($event, TheliaEvents::GENERATE_PDF));

        if (!$event->hasPdf()) {
            throw new \RuntimeException(\sprintf('No PDF was produced for order %s.', $order->getRef()));
        }

        return (string) $event->getPdf();
    }
}
