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

namespace FacturX\Tests\Integration;

use FacturX\Service\InvoiceNumberResolver;
use FacturX\Tests\Support\OrderBuilder;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The number the invoice of the PDF template prints and the number the module stamps
 * in the structured invoice and in the archive must be the same one: a buyer reads the
 * first, an accounting system reads the second.
 *
 * The template ships as its own package on its own release cycle: an installed
 * template that still prints `FA` + the order reference whatever the order says is
 * reported as skipped.
 */
final class PrintedInvoiceNumberTest extends IntegrationTestCase
{
    private const DOCUMENT = 'invoice';
    private const LEGAL_NUMBER = 'INV-2026-0042';

    private OrderBuilder $orders;
    private FixtureFactory $factory;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $invoicePage = $this->template()->getAbsolutePath().\DIRECTORY_SEPARATOR.self::DOCUMENT.'.html.twig';
        if (!file_exists($invoicePage) || !str_contains((string) file_get_contents($invoicePage), 'INVOICE_REF')) {
            self::markTestSkipped('The installed PDF template does not print the legal invoice number yet.');
        }

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->orders = new OrderBuilder($this->factory, $this->getPropelConnection());
        $this->configure(['pdf_invoice_ref_from' => '']);
    }

    protected function tearDown(): void
    {
        foreach ($this->configurationToRestore as $name => $value) {
            ConfigQuery::write($name, $value ?? '');
        }

        parent::tearDown();
    }

    public function testAnOrderWithALegalNumberPrintsIt(): void
    {
        $order = $this->invoicedOrder(self::LEGAL_NUMBER);

        $this->assertPrints(self::LEGAL_NUMBER, $order);
        self::assertStringNotContainsString($this->legacyNumber($order), $this->render($order));
    }

    public function testAnOrderWithoutALegalNumberPrintsTheLegacyOne(): void
    {
        $order = $this->invoicedOrder(null);

        $this->assertPrints($this->legacyNumber($order), $order);
    }

    public function testAnOrderInvoicedBeforeTheCutOffKeepsTheLegacyNumberItWasIssuedUnder(): void
    {
        $order = $this->invoicedOrder(self::LEGAL_NUMBER);
        $this->configure(['pdf_invoice_ref_from' => (clone $order->getInvoiceDate())->modify('+1 day')->format('Y-m-d')]);

        $this->assertPrints($this->legacyNumber($order), $order);
        self::assertStringNotContainsString(self::LEGAL_NUMBER, $this->render($order));
    }

    public function testAnOrderInvoicedOnTheCutOffDayPrintsItsLegalNumber(): void
    {
        $order = $this->invoicedOrder(self::LEGAL_NUMBER);
        $this->configure(['pdf_invoice_ref_from' => $order->getInvoiceDate()->format('Y-m-d')]);

        $this->assertPrints(self::LEGAL_NUMBER, $order);
    }

    public function testAnOrderThatIsNotInvoicedYetPrintsNoNumber(): void
    {
        $order = $this->factory->order();
        $order->setInvoiceRef(self::LEGAL_NUMBER)->save($this->getPropelConnection());

        $html = $this->render($order);

        self::assertNull((new InvoiceNumberResolver())->resolve($order));
        self::assertStringNotContainsString(self::LEGAL_NUMBER, $html);
        self::assertStringNotContainsString($this->legacyNumber($order), $html);
    }

    private function assertPrints(string $number, Order $order): void
    {
        self::assertSame($number, (new InvoiceNumberResolver())->resolve($order), 'The module and the test disagree on the number.');
        self::assertStringContainsString(': <span>'.$number.'</span>', $this->render($order), 'The invoice does not print the number the module stamps.');
    }

    private function invoicedOrder(?string $legalNumber): Order
    {
        $order = $this->orders->paid([['price' => '100.000000', 'quantity' => 1, 'taxes' => ['20.000000']]]);
        $order->setInvoiceRef($legalNumber)->setInvoiceDate(new \DateTime('2026-10-01 10:00:00'))->save($this->getPropelConnection());

        return $order;
    }

    private function legacyNumber(Order $order): string
    {
        return 'FA'.substr($order->getRef(), 3);
    }

    private function render(Order $order): string
    {
        $template = $this->template();
        $parser = $this->getService(ParserResolver::class)->getParser($template->getAbsolutePath(), self::DOCUMENT);
        $parser->setTemplateDefinition($template, true);

        return $parser->render(self::DOCUMENT, ['order_id' => $order->getId()]);
    }

    private function template(): TemplateDefinition
    {
        return $this->getService(TemplateHelperInterface::class)->getActivePdfTemplate();
    }

    /**
     * @param array<string, string> $values
     */
    private function configure(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->configurationToRestore[$name] ??= ConfigQuery::read($name);
            ConfigQuery::write($name, $value);
        }
    }
}
