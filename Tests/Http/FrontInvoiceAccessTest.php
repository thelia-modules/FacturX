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

namespace FacturX\Tests\Http;

use FacturX\Domain\Profile;
use FacturX\FacturX;
use FacturX\Service\Configuration;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Tests\Support\OrderBuilder;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * A customer reaches the issued invoice through the front-office theme, whose route
 * hands the document to the GENERATE_PDF listeners. The module must serve the archived
 * Factur-X document to the customer who owns the order and to nobody else, and must
 * never generate one in that request.
 *
 * The route belongs to the theme, which ships as its own package: a shop without it is
 * reported as skipped.
 */
final class FrontInvoiceAccessTest extends WebIntegrationTestCase
{
    private const INVOICE_ROUTE = 'account_order_pdf_invoice';
    private const ARCHIVED_DOCUMENT = 'archived-factur-x-document';

    private ?CustomerSessionInjector $injector = null;
    private FixtureFactory $factory;
    private OrderBuilder $orders;
    private string $storage;
    private ?string $storageToRestore = null;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (null === $this->getService(RouterInterface::class)->getRouteCollection()->get(self::INVOICE_ROUTE)) {
            self::markTestSkipped('The installed front-office theme has no invoice PDF route.');
        }

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->orders = new OrderBuilder($this->factory, $this->getPropelConnection());
        $this->storage = sys_get_temp_dir().'/facturx-front-'.bin2hex(random_bytes(4));

        $this->storageToRestore = FacturX::getConfigValue(FacturX::CONFIG_STORAGE_PATH);
        FacturX::setConfigValue(FacturX::CONFIG_STORAGE_PATH, $this->storage);

        foreach ([
            'store_name' => 'Test Shop',
            'store_address1' => '5 rue Rochon',
            'store_zipcode' => '63000',
            'store_city' => 'Clermont-Ferrand',
            'store_siret' => '30326504500003',
            'store_vat_intracom' => 'FR40303265045',
            'store_country' => (string) $this->factory->country()->getId(),
        ] as $name => $value) {
            $this->configurationToRestore[$name] ??= ConfigQuery::read($name);
            ConfigQuery::write($name, $value);
        }
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        FacturX::setConfigValue(FacturX::CONFIG_STORAGE_PATH, (string) $this->storageToRestore);

        foreach ($this->configurationToRestore as $name => $value) {
            ConfigQuery::write($name, $value ?? '');
        }
        ConfigQuery::resetCache();

        if (is_dir($this->storage)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }

        parent::tearDown();
    }

    public function testTheOwnerOfTheOrderIsServedTheArchivedInvoice(): void
    {
        $owner = $this->customer();
        $order = $this->archivedOrderOf($owner);

        $this->injector?->setCustomer($owner);
        $this->client->request('GET', $this->invoiceUrl($order));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::ARCHIVED_DOCUMENT, $this->responseBody());
    }

    public function testAnotherCustomerIsNeverServedTheInvoiceOfSomeoneElse(): void
    {
        $order = $this->archivedOrderOf($this->customer());

        $this->injector?->setCustomer($this->customer());
        $this->client->request('GET', $this->invoiceUrl($order));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::ARCHIVED_DOCUMENT, $this->responseBody());
    }

    public function testAVisitorWhoIsNotSignedInIsNeverServedAnInvoice(): void
    {
        $order = $this->archivedOrderOf($this->customer());

        $this->client->request('GET', $this->invoiceUrl($order));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::ARCHIVED_DOCUMENT, $this->responseBody());
    }

    public function testTheOwnerViewingAnInvoiceNobodyIssuedYetDoesNotGenerateIt(): void
    {
        if (!class_exists(\Atgp\FacturX\Writer::class)) {
            self::markTestSkipped('The atgp/factur-x library is not installed.');
        }

        $owner = $this->customer();
        $order = $this->paidOrderOf($owner);

        $this->injector?->setCustomer($owner);
        $this->client->request('GET', $this->invoiceUrl($order));

        self::assertDirectoryDoesNotExist($this->storage, 'A visitor request must not issue the invoice.');
    }

    private function archivedOrderOf(Customer $customer): Order
    {
        $order = $this->paidOrderOf($customer);
        (new InvoiceArchive(new Configuration()))->store((string) (new InvoiceNumberResolver())->resolve($order), $order->getInvoiceDate(), self::ARCHIVED_DOCUMENT, Profile::En16931);

        return $order;
    }

    private function paidOrderOf(Customer $customer): Order
    {
        return $this->orders->paid(
            [['price' => '100.000000', 'quantity' => 2, 'taxes' => ['20.000000']]],
            customer: $customer,
        );
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function invoiceUrl(Order $order): string
    {
        return $this->getService(RouterInterface::class)->generate(self::INVOICE_ROUTE, ['orderId' => $order->getId()]);
    }

    private function responseBody(): string
    {
        $response = $this->client->getResponse();

        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }
}
