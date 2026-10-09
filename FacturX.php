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

namespace FacturX;

use FacturX\Domain\Profile;
use FacturX\EventListener\InvoiceMailListener;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\Translation\Translator;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Module\BaseModule;

class FacturX extends BaseModule
{
    public const MODULE_DOMAIN = 'facturx';

    public const CONFIG_SIRET = 'facturx_siret';
    public const CONFIG_TVA_INTRACOM = 'facturx_tva_intracommunautaire';
    public const CONFIG_IS_ENABLED = 'facturx_is_enabled';
    public const CONFIG_STORAGE_PATH = 'facturx_storage_path';
    public const CONFIG_PROFILE = 'facturx_profile';
    public const CONFIG_SEND_MAIL = 'facturx_send_invoice_mail';
    public const CONFIG_REQUIRE_BUYER_VAT = 'facturx_require_buyer_vat';

    public function postActivation(?ConnectionInterface $con = null): void
    {
        $defaults = [
            self::CONFIG_SIRET => '',
            self::CONFIG_TVA_INTRACOM => '',
            self::CONFIG_IS_ENABLED => '1',
            self::CONFIG_STORAGE_PATH => THELIA_LOCAL_DIR.'media/documents/facturx/',
            self::CONFIG_PROFILE => Profile::DEFAULT->value,
            self::CONFIG_SEND_MAIL => '0',
            self::CONFIG_REQUIRE_BUYER_VAT => '0',
        ];

        foreach ($defaults as $key => $value) {
            if (null === ModuleConfigQuery::create()
                ->filterByModuleId($this->getModuleModel()->getId())
                ->filterByName($key)
                ->findOne()
            ) {
                self::setConfigValue($key, $value);
            }
        }

        $storagePath = (string) self::getConfigValue(self::CONFIG_STORAGE_PATH);
        if (!is_dir($storagePath) && !mkdir($storagePath, 0o775, true) && !is_dir($storagePath)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $storagePath));
        }

        $this->seedInvoiceMessage();
    }

    private function seedInvoiceMessage(): void
    {
        if (null !== MessageQuery::create()->findOneByName(InvoiceMailListener::MESSAGE_CODE)) {
            return;
        }

        $message = new Message();
        $message
            ->setName(InvoiceMailListener::MESSAGE_CODE)
            ->setHtmlLayoutFileName('')
            ->setHtmlTemplateFileName('invoice-mail.html.twig')
            ->setTextLayoutFileName('')
            ->setTextTemplateFileName('invoice-mail.txt.twig');

        foreach (LangQuery::create()->find() as $language) {
            $locale = $language->getLocale();
            $translated = self::mailSubject($locale);

            $message->setLocale($locale);
            $message->setTitle($translated);
            $message->setSubject($translated);
        }

        $message->save();
    }

    /**
     * The subject is stored in the message and rendered by the mailer with Twig, so the
     * variables of the mail are written as Twig placeholders, not as translation parameters.
     */
    public static function mailSubject(string $locale): string
    {
        return Translator::getInstance()->trans(
            'Your invoice %invoice% for order %order%',
            ['%invoice%' => '{{ invoice_ref }}', '%order%' => '{{ order_ref }}'],
            self::MODULE_DOMAIN,
            $locale,
        );
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Domain/*',
                __DIR__.'/Tests/*',
                __DIR__.'/FacturX.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
