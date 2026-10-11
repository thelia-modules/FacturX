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

use Atgp\FacturX\XsdValidator;
use FacturX\Domain\Cents;
use FacturX\Domain\InvoiceData;
use FacturX\Domain\Profile;
use FacturX\Service\CiiXmlBuilder;
use FacturX\Service\InvoiceDataBuilder;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Tests\Support\OrderBuilder;
use FacturX\Tests\Support\StubConfiguration;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

final class InvoiceDataBuilderTest extends IntegrationTestCase
{
    private OrderBuilder $orders;

    /** @var array<string, ?string> */
    private array $configurationToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new OrderBuilder(new FixtureFactory($this->getPropelConnection()), $this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        foreach ($this->configurationToRestore as $name => $value) {
            ConfigQuery::write($name, $value ?? '');
        }

        parent::tearDown();
    }

    public function testTheTotalsOfSeveralRatesWithPostageAndDiscountAreTheOnesOfTheOrder(): void
    {
        $order = $this->orders->paid(
            [
                ['price' => '100.000000', 'quantity' => 2, 'taxes' => ['20.000000']],
                ['price' => '50.000000', 'quantity' => 1, 'taxes' => ['2.750000']],
                ['price' => '0.000000', 'quantity' => 1, 'taxes' => []],
            ],
            postage: '12.00',
            postageTax: '2.00',
            discount: '15.00',
        );

        $data = $this->build($order);

        self::assertSame([], $data->inconsistencies);
        self::assertSame($this->theliaTotal($order), $data->grandTotalCents);
        self::assertSame(
            ['20.00', '5.50'],
            array_map(static fn ($entry): string => $entry->category->rate, $data->taxBreakdown),
        );
        self::assertSame($data->taxBasisTotalCents, array_sum(array_map(static fn ($entry): int => $entry->basisCents, $data->taxBreakdown)));
        self::assertSame($data->taxTotalCents, array_sum(array_map(static fn ($entry): int => $entry->taxCents, $data->taxBreakdown)));
        self::assertSame($data->grandTotalCents, $data->taxBasisTotalCents + $data->taxTotalCents);
        self::assertGreaterThan(0, $data->allowanceTotalCents);
        self::assertSame(1000, $data->chargeTotalCents);
    }

    public function testAFreeLineJoinsTheDominantCategoryInsteadOfOpeningAZeroRatedOne(): void
    {
        $order = $this->orders->paid([
            ['price' => '100.000000', 'quantity' => 1, 'taxes' => ['20.000000']],
            ['price' => '0.000000', 'quantity' => 1, 'taxes' => [], 'title' => 'Gift wrapping'],
        ]);

        $data = $this->build($order);

        self::assertCount(1, $data->taxBreakdown);
        self::assertSame('S', $data->lines[1]->category->code);
    }

    public function testAnUnfavourableRoundingKeepsTheTotalOfTheOrderInTheSumOfRoundingsMode(): void
    {
        $this->setRoundingMode(ConfigQuery::ROUNDING_MODE_SUM_OF_ROUNDINGS);
        $order = $this->orders->paid([['price' => '8.330000', 'quantity' => 3, 'taxes' => ['1.670000']]]);

        $data = $this->build($order);

        self::assertSame([], $data->inconsistencies);
        self::assertSame($this->theliaTotal($order), $data->grandTotalCents);
    }

    public function testAnUnfavourableRoundingKeepsTheTotalOfTheOrderInTheRoundingOfSumsMode(): void
    {
        $this->setRoundingMode(ConfigQuery::ROUNDING_MODE_ROUNDING_OF_SUMS);
        $order = $this->orders->paid([['price' => '8.333300', 'quantity' => 3, 'taxes' => ['1.666660']], ['price' => '0.335000', 'quantity' => 7, 'taxes' => ['0.067000']]]);

        $data = $this->build($order);

        self::assertSame([], $data->inconsistencies);
        self::assertSame($this->theliaTotal($order), $data->grandTotalCents);
    }

    public function testALineSoldByAFractionalQuantityKeepsItsQuantityAndTheTotalOfTheOrder(): void
    {
        foreach ([ConfigQuery::ROUNDING_MODE_SUM_OF_ROUNDINGS, ConfigQuery::ROUNDING_MODE_ROUNDING_OF_SUMS] as $mode) {
            $this->setRoundingMode($mode);
            $order = $this->orders->paid([['price' => '10.000000', 'quantity' => 1.5, 'taxes' => ['2.000000']]]);

            $data = $this->build($order);

            self::assertSame(1500, $data->lines[0]->netCents, 'The line bills 1.5 units of 10.00.');
            self::assertSame(300, $data->lines[0]->taxCents);
            self::assertSame([], $data->inconsistencies, 'A valid order is not refused as inconsistent.');
            self::assertSame($this->theliaTotal($order), $data->grandTotalCents);
            self::assertStringContainsString('<ram:BilledQuantity unitCode="C62">1.5</ram:BilledQuantity>', (new CiiXmlBuilder())->build($data, Profile::En16931));
        }
    }

    public function testTheXmlOfARealOrderIsValidAgainstTheSchemaOfEveryProfile(): void
    {
        $order = $this->orders->paid(
            [['price' => '100.000000', 'quantity' => 2, 'taxes' => ['20.000000']], ['price' => '50.000000', 'quantity' => 1, 'taxes' => ['2.750000']]],
            postage: '12.00',
            postageTax: '2.00',
            discount: '15.00',
        );
        $data = $this->build($order);

        foreach (Profile::cases() as $profile) {
            (new XsdValidator())->validateWithException((new CiiXmlBuilder())->build($data, $profile), $profile->value);
        }

        self::assertTrue(true);
    }

    public function testAnOrderWithNoTaxIsZeroRatedUnlessTheShopIsNotLiableToVat(): void
    {
        $order = $this->orders->paid([['price' => '100.000000', 'quantity' => 1, 'taxes' => []]]);

        self::assertSame('Z', $this->build($order)->taxBreakdown[0]->category->code);

        $this->setConfiguration('store_vat_exempt', '1');

        $franchise = $this->build($order)->taxBreakdown[0]->category;
        self::assertSame('E', $franchise->code);
        self::assertSame('VATEX-FR-FRANCHISE', $franchise->exemptionReasonCode);
    }

    public function testAnExemptedOrderIsAnIntraCommunitySupply(): void
    {
        $order = $this->orders->paid([['price' => '100.000000', 'quantity' => 1, 'taxes' => []]]);

        $address = $order->getOrderAddressRelatedByInvoiceOrderAddressId();
        $address->setVatExempted(1)->setVatNumber('BE0417497106')->save($this->getPropelConnection());

        $data = $this->build($order);

        self::assertSame('K', $data->taxBreakdown[0]->category->code);
        self::assertSame('BE0417497106', $data->buyer->vatNumber);
        self::assertSame(0, $data->taxTotalCents);
    }

    public function testTheNumberIsTheInvoiceRefAndFallsBackOnTheOrderReferenceBeforeTheCutoff(): void
    {
        $order = $this->orders->paid([['price' => '10.000000', 'quantity' => 1, 'taxes' => []]]);
        $resolver = new InvoiceNumberResolver();

        self::assertSame('FA'.substr($order->getRef(), 3), $resolver->resolve($order));

        $order->setInvoiceRef('2026-000042')->save($this->getPropelConnection());
        self::assertSame('2026-000042', $resolver->resolve($order));

        $this->setConfiguration(InvoiceNumberResolver::CONFIG_SINCE, '2999-01-01');
        self::assertSame('FA'.substr($order->getRef(), 3), $resolver->resolve($order));
    }

    public function testTheDeliveryCountryIsTheOneOfTheDeliveryAddressAndNothingMoreOfIt(): void
    {
        $order = $this->orders->paid([['price' => '10.000000', 'quantity' => 1, 'taxes' => []]]);
        $deliveryAddress = $order->getOrderAddressRelatedByDeliveryOrderAddressId();

        $data = $this->build($order);

        self::assertNotSame('', $data->deliveryCountryCode);
        self::assertSame(
            (string) \Thelia\Model\CountryQuery::create()->findPk($deliveryAddress->getCountryId())?->getIsoalpha2(),
            $data->deliveryCountryCode,
        );
        self::assertStringNotContainsString((string) $deliveryAddress->getAddress1(), (new CiiXmlBuilder())->build($data, Profile::En16931), 'Only the country of the delivery address is written.');
    }

    public function testAnOrderThatIsNotPaidHasNoNumber(): void
    {
        $order = $this->orders->paid([['price' => '10.000000', 'quantity' => 1, 'taxes' => []]]);
        $order->setInvoiceDate(null);

        self::assertNull((new InvoiceNumberResolver())->resolve($order));
    }

    private function build(Order $order): InvoiceData
    {
        return (new InvoiceDataBuilder(new InvoiceNumberResolver(), new StubConfiguration(sys_get_temp_dir())))->build($order);
    }

    private function theliaTotal(Order $order): int
    {
        return Cents::from($order->getTotalAmount());
    }

    private function setRoundingMode(int $mode): void
    {
        $this->setConfiguration('order_rounding_mode', (string) $mode);
    }

    private function setConfiguration(string $name, string $value): void
    {
        $this->configurationToRestore[$name] ??= ConfigQuery::read($name);
        ConfigQuery::write($name, $value);
    }
}
