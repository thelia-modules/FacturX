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

use FacturX\Service\GenerationPermit;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * `pdf:render` previews a document from the command line: the Factur-X file is built so it
 * can be opened and read, but it is not archived, because a preview must not freeze an invoice.
 */
final readonly class PdfRenderPreviewListener
{
    private const COMMAND = 'pdf:render';

    public function __construct(private GenerationPermit $permit)
    {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (self::COMMAND === $event->getCommand()?->getName()) {
            $this->permit->beginPreview();
        }
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->permit->endPreview();
    }
}
