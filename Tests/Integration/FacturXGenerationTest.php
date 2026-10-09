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

use Atgp\FacturX\Reader;
use Dompdf\Dompdf;
use FacturX\Domain\Cents;
use FacturX\Domain\IncompleteInvoiceException;
use FacturX\Domain\Profile;
use FacturX\EventListener\InvoiceMailListener;
use FacturX\EventListener\InvoicePdfListener;
use FacturX\Service\CiiXmlBuilder;
use FacturX\Service\CompletenessChecker;
use FacturX\Service\FacturXGenerator;
use FacturX\Service\GenerationPermit;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceDataBuilder;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Service\PdfANormalizer;
use FacturX\Service\RenderingRequest;
use FacturX\Tests\Support\FixedInvoiceRenderer;
use FacturX\Tests\Support\OrderBuilder;
use FacturX\Tests\Support\RecordingMailer;
use FacturX\Tests\Support\StubConfiguration;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\PdfEvent;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

final class FacturXGenerationTest extends IntegrationTestCase
{
    private const STORE_SETTINGS = [
        'store_name' => 'Test Shop',
        'store_address1' => '5 rue Rochon',
        'store_zipcode' => '63000',
        'store_city' => 'Clermont-Ferrand',
        'store_siret' => '30326504500003',
        'store_vat_intracom' => 'FR40303265045',
    ];

    private OrderBuilder $orders;
    private string $storage;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new FixtureFactory($this->getPropelConnection());
        $this->orders = new OrderBuilder($factory, $this->getPropelConnection());
        $this->storage = sys_get_temp_dir().'/facturx-test-'.bin2hex(random_bytes(4));

        $this->configure(self::STORE_SETTINGS + ['store_country' => (string) $factory->country()->getId()]);
    }

    protected function tearDown(): void
    {
        foreach ($this->configurationToRestore as $name => $value) {
            ConfigQuery::write($name, $value ?? '');
        }

        if (is_dir($this->storage)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }

        parent::tearDown();
    }

    public function testTheStructuredInvoiceCarriesTheTotalOfTheOrder(): void
    {
        $order = $this->twoRatesOrder();

        $facturX = $this->generator()->generate($order, $this->pdf());

        $xml = (new Reader())->extractXML($facturX);
        self::assertStringContainsString('<ram:GrandTotalAmount>'.Cents::format(Cents::from($order->getTotalAmount())).'</ram:GrandTotalAmount>', $xml);
        self::assertStringContainsString('<ram:ID>'.(new InvoiceNumberResolver())->resolve($order).'</ram:ID>', $xml);
        self::assertStringContainsString('<ram:ID schemeID="VA">FR40303265045</ram:ID>', $xml);
    }

    public function testAnOrderThatMissesWhatTheProfileNeedsIsRefusedWithTheListOfWhatIsMissing(): void
    {
        $this->configure(['store_vat_intracom' => '']);

        try {
            $this->generator()->generate($this->twoRatesOrder(), $this->pdf());
            self::fail('The invoice should have been refused.');
        } catch (IncompleteInvoiceException $exception) {
            self::assertSame([CompletenessChecker::SELLER_VAT_NUMBER], $exception->report->missing);
        }
    }

    public function testAnIssuedInvoiceIsStoredOnceAndServedAsItWasWhateverTheProfileBecomes(): void
    {
        $order = $this->twoRatesOrder();
        $event = $this->invoiceEvent($order);

        $this->issue(Profile::En16931, $event);
        $issued = (string) $event->getPdf();

        $later = $this->invoiceEvent($order);
        $this->issue(Profile::Basic, $later);

        self::assertStringContainsString('factur-x.xml', $issued);
        self::assertSame($issued, (string) $later->getPdf());
        self::assertSame(
            Profile::En16931,
            (new InvoiceArchive(new StubConfiguration($this->storage)))->profile((string) (new InvoiceNumberResolver())->resolve($order), $order->getInvoiceDate()),
        );
    }

    public function testAVisitorRequestNeverGeneratesTheInvoice(): void
    {
        $order = $this->twoRatesOrder();
        $event = $this->invoiceEvent($order);
        $plain = (string) $event->getPdf();

        $this->listener(Profile::En16931)->onGeneratePdf($event);

        self::assertSame($plain, (string) $event->getPdf());
        self::assertDirectoryDoesNotExist($this->storage);
    }

    public function testAPreviewBuildsTheFacturXFileWithoutArchivingIt(): void
    {
        $order = $this->twoRatesOrder();
        $event = $this->invoiceEvent($order);
        $permit = new GenerationPermit();
        $permit->beginPreview();

        $this->listener(Profile::En16931, permit: $permit)->onGeneratePdf($event);

        self::assertStringContainsString('factur-x.xml', (string) $event->getPdf());
        self::assertDirectoryDoesNotExist($this->storage, 'A preview must not freeze the invoice.');
    }

    public function testAVisitorRequestIsServedTheInvoiceAlreadyIssued(): void
    {
        $order = $this->twoRatesOrder();
        $this->issue(Profile::En16931, $this->invoiceEvent($order));

        $visitor = $this->invoiceEvent($order);
        $this->listener(Profile::En16931)->onGeneratePdf($visitor);

        self::assertStringContainsString('factur-x.xml', (string) $visitor->getPdf());
    }

    public function testATamperedArchiveIsNeverServed(): void
    {
        $order = $this->twoRatesOrder();
        $this->issue(Profile::En16931, $this->invoiceEvent($order));

        $archive = new InvoiceArchive(new StubConfiguration($this->storage));
        file_put_contents($archive->path((string) (new InvoiceNumberResolver())->resolve($order), $order->getInvoiceDate()), 'forged');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fingerprint');
        $this->issue(Profile::En16931, $this->invoiceEvent($order));
    }

    public function testAnOrderThatIsNotInvoicedYetKeepsItsPlainPdf(): void
    {
        $order = $this->twoRatesOrder();
        $order->setInvoiceDate(null);
        $event = $this->invoiceEvent($order);
        $plain = (string) $event->getPdf();

        $this->listener(Profile::En16931)->onGeneratePdf($event);

        self::assertSame($plain, (string) $event->getPdf());
        self::assertDirectoryDoesNotExist($this->storage);
    }

    public function testAnotherDocumentKeepsItsPlainPdf(): void
    {
        $event = $this->invoiceEvent($this->twoRatesOrder());
        $event->setTemplateName('delivery');
        $plain = (string) $event->getPdf();

        $this->listener(Profile::En16931)->onGeneratePdf($event);

        self::assertSame($plain, (string) $event->getPdf());
    }

    public function testAShopThatSwitchedItOffKeepsPlainPdfs(): void
    {
        $event = $this->invoiceEvent($this->twoRatesOrder());
        $plain = (string) $event->getPdf();

        $this->issue(Profile::En16931, $event, enabled: false);

        self::assertSame($plain, (string) $event->getPdf());
    }

    public function testTheInvoiceIsMailedOnceWithTheDocumentAttached(): void
    {
        $order = $this->twoRatesOrder();
        $mailer = new RecordingMailer();
        $listener = $this->mailListener($mailer, 'PDF-BYTES');

        $listener->onOrderStatusUpdate(new OrderEvent($order));
        $listener->onOrderStatusUpdate(new OrderEvent($order));

        self::assertCount(1, $mailer->sent);
        self::assertSame(InvoiceMailListener::MESSAGE_CODE, $mailer->created[0]['code']);
        self::assertSame((new InvoiceNumberResolver())->resolve($order), $mailer->created[0]['parameters']['invoice_ref']);
        self::assertSame('PDF-BYTES', $mailer->sent[0]->getAttachments()[0]->getBody());
    }

    public function testTheInvoiceIsMailedFromTheCommandLineInsideARequestThatIsRemovedAfterwards(): void
    {
        $order = $this->twoRatesOrder();
        $mailer = new RecordingMailer();

        $this->mailListener($mailer, 'PDF-BYTES', request: false)->onOrderStatusUpdate(new OrderEvent($order));

        self::assertCount(1, $mailer->sent);
        self::assertTrue($mailer->created[0]['request'], 'The message was rendered without a request.');
        self::assertNull($mailer->requests->getCurrentRequest());
        self::assertSame('PDF-BYTES', $mailer->sent[0]->getAttachments()[0]->getBody());
    }

    public function testNothingIsMailedWhenTheShopDidNotAskForIt(): void
    {
        $mailer = new RecordingMailer();

        $this->mailListener($mailer, 'PDF', mail: false)->onOrderStatusUpdate(new OrderEvent($this->twoRatesOrder()));

        self::assertSame([], $mailer->sent);
    }

    public function testNothingIsMailedForAnOrderThatIsNotPaid(): void
    {
        $order = $this->twoRatesOrder();
        $order->setStatusId(\Thelia\Model\OrderStatusQuery::create()->findOneByCode('not_paid')->getId());
        $mailer = new RecordingMailer();

        $this->mailListener($mailer, 'PDF')->onOrderStatusUpdate(new OrderEvent($order));

        self::assertSame([], $mailer->sent);
    }

    private function twoRatesOrder(): Order
    {
        return $this->orders->paid(
            [
                ['price' => '100.000000', 'quantity' => 2, 'taxes' => ['20.000000']],
                ['price' => '50.000000', 'quantity' => 1, 'taxes' => ['2.750000']],
            ],
            postage: '12.00',
            postageTax: '2.00',
            discount: '15.00',
        );
    }

    private function generator(Profile $profile = Profile::En16931): FacturXGenerator
    {
        $configuration = new StubConfiguration($this->storage, $profile);

        return new FacturXGenerator(
            new InvoiceDataBuilder(new InvoiceNumberResolver(), $configuration),
            new CompletenessChecker(),
            new CiiXmlBuilder(),
            new PdfANormalizer(''),
            $configuration,
        );
    }

    private function issue(Profile $profile, PdfEvent $event, bool $enabled = true): void
    {
        $permit = new GenerationPermit();
        $permit->during(fn () => $this->listener($profile, $enabled, $permit)->onGeneratePdf($event));
    }

    private function listener(Profile $profile, bool $enabled = true, ?GenerationPermit $permit = null): InvoicePdfListener
    {
        $configuration = new StubConfiguration($this->storage, $profile, $enabled);

        return new InvoicePdfListener(
            $configuration,
            $this->generator($profile),
            new InvoiceArchive($configuration),
            new InvoiceNumberResolver(),
            $permit ?? new GenerationPermit(),
        );
    }

    private function mailListener(RecordingMailer $mailer, string $pdf, bool $mail = true, bool $request = true): InvoiceMailListener
    {
        $configuration = new StubConfiguration($this->storage, mail: $mail);
        $requests = new RequestStack();
        if ($request) {
            $requests->push(\Symfony\Component\HttpFoundation\Request::create('/'));
        }
        $mailer->requests = $requests;

        return new InvoiceMailListener(
            $configuration,
            new InvoiceNumberResolver(),
            new InvoiceArchive($configuration),
            new FixedInvoiceRenderer($pdf),
            $mailer,
            new RenderingRequest($requests),
        );
    }

    private function invoiceEvent(Order $order): PdfEvent
    {
        $event = new PdfEvent('<html></html>');
        $event->setTemplateName('invoice');
        $event->setObject($order);
        $event->setPdf($this->pdf());

        return $event;
    }

    private function pdf(): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<html><body><p>Invoice</p></body></html>');
        $dompdf->render();

        return $dompdf->output();
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
