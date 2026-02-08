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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;

#[Route('/admin/facturx', name: 'facturx_')]
final class ConfigurationController extends AdminController
{
    #[Route('/configure', name: 'configure', methods: ['POST'])]
    public function save(ParserContext $parserContext): RedirectResponse|Response|null
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'FacturX', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm('facturx_configuration');

        try {
            $data = $this->validateForm($form)->getData();

            FacturX::setConfigValue(FacturX::CONFIG_SIRET, $data['facturx_siret'] ?? '');
            FacturX::setConfigValue(FacturX::CONFIG_TVA_INTRACOM, $data['facturx_tva_intracommunautaire'] ?? '');
            FacturX::setConfigValue(FacturX::CONFIG_IS_ENABLED, !empty($data['facturx_is_enabled']) ? '1' : '0');

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $ex) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            $errorMessage = $ex->getMessage();
        }

        $form->setErrorMessage($errorMessage);

        $parserContext
            ->addForm($form)
            ->setGeneralError($errorMessage);

        return $this->generateErrorRedirect($form);
    }
}
