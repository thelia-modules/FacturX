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
use FacturX\Service\GenerationLimiter;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Tests\Support\OrderBuilder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class BackOfficeInvoiceTest extends WebIntegrationTestCase
{
    private const ARCHIVED_DOCUMENT = 'archived-factur-x-document';

    private ?AdminSessionInjector $injector = null;
    private FixtureFactory $factory;
    private OrderBuilder $orders;
    private string $storage;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];
    private ?string $storageToRestore = null;
    private ?string $profileToRestore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->orders = new OrderBuilder($this->factory, $this->getPropelConnection());
        $this->storage = sys_get_temp_dir().'/facturx-http-'.bin2hex(random_bytes(4));

        $this->storageToRestore = FacturX::getConfigValue(FacturX::CONFIG_STORAGE_PATH);
        $this->profileToRestore = FacturX::getConfigValue(FacturX::CONFIG_PROFILE);
        FacturX::setConfigValue(FacturX::CONFIG_STORAGE_PATH, $this->storage);

        $this->configure([
            'store_name' => 'Test Shop',
            'store_address1' => '5 rue Rochon',
            'store_zipcode' => '63000',
            'store_city' => 'Clermont-Ferrand',
            'store_siret' => '30326504500003',
            'store_vat_intracom' => 'FR40303265045',
            'store_country' => (string) $this->factory->country()->getId(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        FacturX::setConfigValue(FacturX::CONFIG_STORAGE_PATH, (string) $this->storageToRestore);
        FacturX::setConfigValue(FacturX::CONFIG_PROFILE, (string) $this->profileToRestore);

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

    public function testTheOrderSheetOffersTheInvoiceWhenEverythingTheProfileNeedsIsThere(): void
    {
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('[data-testid="facturx-order-panel"]'));
        self::assertCount(1, $crawler->filter('[data-testid="facturx-complete"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-missing"]'));
        self::assertCount(1, $crawler->filter('[data-testid="facturx-download"]'));
        self::assertCount(1, $crawler->filter('[data-testid="facturx-generate"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-archived"]'));
    }

    public function testTheOrderSheetListsWhatPreventsTheInvoice(): void
    {
        $this->configure(['store_vat_intracom' => '']);
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('[data-testid="facturx-missing"]'));
        self::assertCount(1, $crawler->filter('[data-testid="facturx-missing"] li[data-code="seller_vat_number"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-download"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-generate"]'));
    }

    public function testTheOrderSheetOfAnOrderThatIsNotPaidSaysTheInvoiceComesWithThePayment(): void
    {
        $order = $this->factory->order();
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('[data-testid="facturx-order-panel"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-complete"], [data-testid="facturx-missing"]'));
    }

    public function testIssuingTheInvoiceFromTheSheetArchivesIt(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="facturx-generate"]')->form());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/admin/order/update/'.$order->getId(), (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFileExists($this->archivePath($order));
        self::assertStringContainsString('factur-x.xml', (string) file_get_contents($this->archivePath($order)));
    }

    public function testTheSheetOfAnArchivedInvoiceSaysSoAndOffersNothingToIssue(): void
    {
        $order = $this->paidOrder();
        (new InvoiceArchive(new Configuration()))->store((string) (new InvoiceNumberResolver())->resolve($order), $order->getInvoiceDate(), 'archived-invoice', Profile::Extended);
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('[data-testid="facturx-archived"]'));
        self::assertCount(0, $crawler->filter('[data-testid="facturx-generate"]'));
        self::assertCount(1, $crawler->filter('[data-testid="facturx-download"]'));
        self::assertStringContainsString('Extended', $crawler->filter('[data-testid="facturx-order-panel"]')->text(), 'The sheet names the profile the invoice was issued with, not the one configured now.');
    }

    public function testIssuingWithoutTheTokenIsRefusedAndWritesNothing(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', '/admin/module/FacturX/order/'.$order->getId().'/generate');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertDirectoryDoesNotExist($this->storage.'/'.date('Y'));
    }

    public function testIssuingIsRefusedToAnAdminWhoCannotUpdateOrders(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));

        $this->assertPageRenders('/admin/order/update/'.$order->getId());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="facturx-generate"]')->form());

        self::assertFileDoesNotExist($this->archivePath($order));
    }

    public function testTheDownloadIsAFacturXPdf(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertStringContainsString('factur-x.xml', (string) $response->getContent());
        self::assertStringContainsString('attachment; filename="invoice-', (string) $response->headers->get('Content-Disposition'));
        self::assertFileDoesNotExist($this->archivePath($order), 'Downloading previews the invoice, issuing it is the POST of the sheet.');
    }

    public function testDownloadingDoesNotIssueTheInvoiceForAnAdminWhoCanOnlyViewOrders(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($this->archivePath($order));
    }

    public function testAnAdministratorWhoExhaustedTheGenerationBudgetIsTurnedAway(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();
        $admin = $this->factory->admin();
        $this->loginAs($admin);

        $limiter = $this->getService(GenerationLimiter::class);
        while ($limiter->allows($admin->getId())) {
        }

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($this->archivePath($order));
    }

    public function testAnArchivedInvoiceIsDownloadedAfterTheShopSettingsChanged(): void
    {
        $order = $this->paidOrder();
        $this->archive($order);
        $this->loginAs($this->factory->admin());

        // After the issue, the shop settings no longer satisfy the profile.
        $this->configure(['store_vat_intracom' => '', 'store_siret' => '']);

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The issued invoice is served, not sent back to the order sheet.');
        self::assertSame(self::ARCHIVED_DOCUMENT, (string) $response->getContent());
    }

    public function testAnArchivedInvoiceIsServedToAnAdministratorWhoExhaustedTheGenerationBudget(): void
    {
        $order = $this->paidOrder();
        $this->archive($order);
        $admin = $this->factory->admin();
        $this->loginAs($admin);

        $limiter = $this->getService(GenerationLimiter::class);
        while ($limiter->allows($admin->getId())) {
        }

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'Serving the archive generates nothing and is not turned away.');
        self::assertSame(self::ARCHIVED_DOCUMENT, (string) $response->getContent());
    }

    public function testTheDownloadIsRefusedWithoutAnAdministratorSession(): void
    {
        $order = $this->paidOrder();
        $this->archive($order);

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::ARCHIVED_DOCUMENT, (string) $this->client->getResponse()->getContent());
    }

    public function testTheDownloadIsRefusedToAnAdminWhoCannotViewOrders(): void
    {
        $order = $this->paidOrder();
        $this->archive($order);
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::MODULE => [AccessManager::VIEW]]));

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::ARCHIVED_DOCUMENT, (string) $this->client->getResponse()->getContent());
    }

    public function testIssuingIsRefusedWithoutAnAdministratorSession(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $order = $this->paidOrder();

        $this->client->request('POST', '/admin/module/FacturX/order/'.$order->getId().'/generate');

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($this->archivePath($order));
    }

    public function testTheConfigurationIsNotSavedWithoutAnAdministratorSession(): void
    {
        FacturX::setConfigValue(FacturX::CONFIG_PROFILE, 'en16931');

        $this->client->request('POST', '/admin/module/FacturX/save', ['facturx_configuration' => ['facturx_profile' => 'minimum']]);

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('en16931', FacturX::getConfigValue(FacturX::CONFIG_PROFILE));
    }

    public function testTheConfigurationIsNotSavedByAnAdminWhoCannotUpdateTheModule(): void
    {
        FacturX::setConfigValue(FacturX::CONFIG_PROFILE, 'en16931');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/module/FacturX');
        $form = $this->client->getCrawler()->filter('[data-testid="facturx-form"]')->form();
        $form['facturx_configuration[facturx_profile]'] = 'basic';

        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));
        $this->client->submit($form);

        self::assertSame('en16931', FacturX::getConfigValue(FacturX::CONFIG_PROFILE), 'A valid form posted by an admin who cannot update the module must write nothing.');
    }

    public function testTheDownloadOfAnInvoiceThatCannotBeIssuedSendsBackToTheOrderSheet(): void
    {
        $this->skipUnlessTheLibraryIsLoaded();
        $this->configure(['store_vat_intracom' => '']);
        $order = $this->paidOrder();
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/admin/order/update/'.$order->getId(), (string) $this->client->getResponse()->headers->get('Location'));
        self::assertDirectoryDoesNotExist($this->storage.'/'.date('Y'));
    }

    public function testTheDownloadOfAnOrderThatIsNotInvoicedIsNotFound(): void
    {
        $order = $this->factory->order();
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/module/FacturX/order/'.$order->getId().'/download');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheConfigurationScreenSavesTheProfile(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/module/FacturX');
        $form = $this->client->getCrawler()->filter('[data-testid="facturx-form"]')->form();
        $form['facturx_configuration[facturx_profile]'] = 'basic';
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('basic', FacturX::getConfigValue(FacturX::CONFIG_PROFILE));
    }

    private function paidOrder(): Order
    {
        return $this->orders->paid(
            [['price' => '100.000000', 'quantity' => 2, 'taxes' => ['20.000000']]],
            postage: '12.00',
            postageTax: '2.00',
        );
    }

    private function archive(Order $order): void
    {
        (new InvoiceArchive(new Configuration()))->store((string) (new InvoiceNumberResolver())->resolve($order), $order->getInvoiceDate(), self::ARCHIVED_DOCUMENT, Profile::En16931);
    }

    private function archivePath(Order $order): string
    {
        return \sprintf('%s/%s/%s.pdf', $this->storage, $order->getInvoiceDate()->format('Y'), (new InvoiceNumberResolver())->resolve($order));
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }

    private function skipUnlessTheLibraryIsLoaded(): void
    {
        if (!class_exists(\Atgp\FacturX\Writer::class)) {
            self::markTestSkipped('The atgp/factur-x library is not installed.');
        }
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
