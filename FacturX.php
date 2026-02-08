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

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Module\BaseModule;

class FacturX extends BaseModule
{
    public const MODULE_DOMAIN = 'facturx';

    public const CONFIG_SIRET = 'facturx_siret';
    public const CONFIG_TVA_INTRACOM = 'facturx_tva_intracommunautaire';
    public const CONFIG_IS_ENABLED = 'facturx_is_enabled';
    public const CONFIG_STORAGE_PATH = 'facturx_storage_path';

    public function postActivation($con = null): void
    {
        $defaults = [
            self::CONFIG_SIRET => '',
            self::CONFIG_TVA_INTRACOM => '',
            self::CONFIG_IS_ENABLED => '0',
            self::CONFIG_STORAGE_PATH => THELIA_LOCAL_DIR.'/media/documents/facturx/',
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

        $storagePath = self::getConfigValue(self::CONFIG_STORAGE_PATH);
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0o775, true) && !is_dir($storagePath)) {
                throw new \RuntimeException(\sprintf('Directory "%s" was not created', $storagePath));
            }
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*'])
            ->autowire()
            ->autoconfigure();
    }
}
