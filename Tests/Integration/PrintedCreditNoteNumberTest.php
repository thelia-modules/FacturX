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

use CreditNote\Service\CreditNotePdfRenderer;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Tests\Support\CreditNoteBuilder;
use FacturX\Tests\Support\OrderBuilder;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A credit note quotes the invoice it cancels, and the number it quotes must be the one
 * the invoice itself prints and the one the module stamps in the structured invoice.
 *
 * Both the CreditNote module and the template ship on their own release cycle: a shop
 * without the module active, or with a template that still quotes the legal number alone,
 * is reported as skipped.
 */
final class PrintedCreditNoteNumberTest extends IntegrationTestCase
{
    private const LEGAL_NUMBER = 'INV-2026-0042';

    private FixtureFactory $factory;
    private OrderBuilder $orders;
    private CreditNoteBuilder $creditNotes;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!ModuleQuery::create()->findOneByCode('CreditNote')?->getActivate()) {
            self::markTestSkipped('The CreditNote module is not active in the test database.');
        }

        $page = $this->getService(TemplateHelperInterface::class)->getActivePdfTemplate()->getAbsolutePath().\DIRECTORY_SEPARATOR.'credit-note.html.twig';
        if (!file_exists($page) || !str_contains((string) file_get_contents($page), 'Original invoice REF') || !str_contains((string) file_get_contents($page), "'FA' ~")) {
            self::markTestSkipped('The installed PDF template does not quote the invoice number of the order the way the invoice prints it yet.');
        }

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->orders = new OrderBuilder($this->factory, $this->getPropelConnection());
        $this->creditNotes = new CreditNoteBuilder($this->factory, $this->getPropelConnection());
        $this->configure(['pdf_invoice_ref_from' => '']);
    }

    protected function tearDown(): void
    {
        foreach ($this->configurationToRestore as $name => $value) {
            ConfigQuery::write($name, $value ?? '');
        }

        parent::tearDown();
    }

    public function testTheCreditNoteQuotesTheLegalNumberOfTheInvoice(): void
    {
        [$order, $creditNote] = $this->creditedOrder(self::LEGAL_NUMBER);

        $this->assertQuotes(self::LEGAL_NUMBER, $order, $creditNote);
        self::assertStringNotContainsString($this->legacyNumber($order), $this->html($creditNote));
    }

    public function testTheCreditNoteOfAnOrderWithoutALegalNumberQuotesTheLegacyOne(): void
    {
        [$order, $creditNote] = $this->creditedOrder(null);

        $this->assertQuotes($this->legacyNumber($order), $order, $creditNote);
    }

    public function testTheCreditNoteOfAnInvoiceIssuedBeforeTheCutOffQuotesTheNumberItWasIssuedUnder(): void
    {
        [$order, $creditNote] = $this->creditedOrder(self::LEGAL_NUMBER);
        $this->configure(['pdf_invoice_ref_from' => (clone $order->getInvoiceDate())->modify('+1 day')->format('Y-m-d')]);

        $this->assertQuotes($this->legacyNumber($order), $order, $creditNote);
        self::assertStringNotContainsString(self::LEGAL_NUMBER, $this->html($creditNote));
    }

    private function assertQuotes(string $number, Order $order, \CreditNote\Model\CreditNote $creditNote): void
    {
        self::assertSame($number, (new InvoiceNumberResolver())->resolve($order), 'The module and the test disagree on the number.');
        self::assertStringContainsString(': <span>'.$number.'</span>', $this->html($creditNote), 'The credit note does not quote the number the module stamps.');
    }

    /**
     * @return array{Order, \CreditNote\Model\CreditNote}
     */
    private function creditedOrder(?string $legalNumber): array
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $order = $this->orders->paid([['price' => '100.000000', 'quantity' => 1, 'taxes' => ['20.000000']]], customer: $customer);
        $order->setInvoiceRef($legalNumber)->setInvoiceDate(new \DateTime('2026-10-01 10:00:00'))->save($this->getPropelConnection());

        return [$order, $this->creditNotes->accepted($customer, $order)];
    }

    private function legacyNumber(Order $order): string
    {
        return 'FA'.substr($order->getRef(), 3);
    }

    private function html(\CreditNote\Model\CreditNote $creditNote): string
    {
        return $this->getService(CreditNotePdfRenderer::class)->renderHtml($creditNote);
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
