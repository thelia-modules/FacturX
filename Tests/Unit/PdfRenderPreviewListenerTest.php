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

namespace FacturX\Tests\Unit;

use FacturX\EventListener\PdfRenderPreviewListener;
use FacturX\Service\GenerationPermit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class PdfRenderPreviewListenerTest extends TestCase
{
    public function testThePreviewLastsAsLongAsThePdfRenderCommand(): void
    {
        $permit = new GenerationPermit();
        $listener = new PdfRenderPreviewListener($permit);

        $listener->onCommand($this->started('pdf:render'));
        self::assertTrue($permit->isPreview());
        self::assertFalse($permit->isOpen(), 'A preview never issues the invoice.');

        $listener->onTerminate(new ConsoleTerminateEvent(new Command('pdf:render'), new ArrayInput([]), new NullOutput(), 0));
        self::assertFalse($permit->isPreview());
    }

    public function testAnotherCommandOpensNothing(): void
    {
        $permit = new GenerationPermit();

        (new PdfRenderPreviewListener($permit))->onCommand($this->started('cache:clear'));

        self::assertFalse($permit->isPreview());
    }

    private function started(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }
}
