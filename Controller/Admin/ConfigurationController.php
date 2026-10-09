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

namespace FacturX\Controller\Admin;

use FacturX\FacturX;
use FacturX\Form\ConfigurationForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\URL;

final class ConfigurationController extends BaseAdminController
{
    private const CHECKBOXES = [FacturX::CONFIG_IS_ENABLED, FacturX::CONFIG_SEND_MAIL, FacturX::CONFIG_REQUIRE_BUYER_VAT];

    #[Route('/admin/module/FacturX/save', name: 'facturx.configuration.save', methods: ['POST'])]
    public function save(): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'FacturX', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());
        $target = URL::getInstance()->absoluteUrl('/admin/module/'.FacturX::getModuleCode());

        try {
            $data = $this->validateForm($form)->getData();

            foreach ($data as $key => $value) {
                if (!str_starts_with((string) $key, 'facturx_')) {
                    continue;
                }

                FacturX::setConfigValue($key, \in_array($key, self::CHECKBOXES, true) ? ($value ? '1' : '0') : (string) ($value ?? ''));
            }
        } catch (\Throwable $throwable) {
            $this->setupFormErrorContext(
                $this->getTranslator()->trans('Factur-X configuration', [], FacturX::MODULE_DOMAIN),
                $throwable->getMessage(),
                $form,
                $throwable instanceof \Exception ? $throwable : null,
            );

            return $this->generateRedirect($target);
        }

        return new RedirectResponse($target);
    }
}
