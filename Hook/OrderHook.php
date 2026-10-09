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

namespace FacturX\Hook;

use FacturX\Service\Configuration;
use FacturX\Service\FacturXGenerator;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Service\PdfANormalizer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\OrderQuery;

final class OrderHook extends BaseHook
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly FacturXGenerator $generator,
        private readonly InvoiceArchive $archive,
        private readonly InvoiceNumberResolver $numberResolver,
        private readonly PdfANormalizer $normalizer,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'order-edit.bill-top' => [
                ['type' => 'back', 'method' => 'onBillTop'],
            ],
        ];
    }

    public function onBillTop(HookRenderEvent $event): void
    {
        $order = OrderQuery::create()->findPk((int) $event->getArgument('order_id'));

        if (null === $order) {
            return;
        }

        $number = $this->numberResolver->resolve($order);
        $issueDate = $order->getInvoiceDate();
        $report = null !== $number ? $this->generator->report($order) : null;
        $issuedProfile = null !== $number && null !== $issueDate ? $this->archive->profile($number, $issueDate) : null;

        $event->add($this->render('FacturX/order_panel.html.twig', [
            'order_id' => $order->getId(),
            'enabled' => $this->configuration->isEnabled(),
            'profile' => ($issuedProfile ?? $this->configuration->profile())->label(),
            'invoice_number' => $number,
            'missing' => $report->missing ?? [],
            'is_complete' => $report?->isComplete() ?? false,
            'is_archived' => null !== $number && null !== $issueDate && $this->archive->find($number, $issueDate) !== null,
            'has_normalizer' => $this->normalizer->isAvailable(),
        ]));
    }
}
