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

namespace FacturX\Form;

use FacturX\Domain\Profile;
use FacturX\FacturX;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Regex;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

final class ConfigurationForm extends BaseForm
{
    protected function trans(string $str, array $params = []): string
    {
        return Translator::getInstance()->trans($str, $params, FacturX::MODULE_DOMAIN);
    }

    protected function buildForm(): void
    {
        $profiles = [];
        foreach (Profile::cases() as $profile) {
            $profiles[$profile->label()] = $profile->value;
        }

        $this->formBuilder
            ->add(FacturX::CONFIG_PROFILE, ChoiceType::class, [
                'required' => true,
                'choices' => $profiles,
                'data' => FacturX::getConfigValue(FacturX::CONFIG_PROFILE, Profile::DEFAULT->value),
                'label' => $this->trans('Conformance profile'),
            ])
            ->add(FacturX::CONFIG_IS_ENABLED, CheckboxType::class, [
                'required' => false,
                'data' => '1' === FacturX::getConfigValue(FacturX::CONFIG_IS_ENABLED, '0'),
                'label' => $this->trans('Embed the structured invoice in the invoice PDF'),
            ])
            ->add(FacturX::CONFIG_SEND_MAIL, CheckboxType::class, [
                'required' => false,
                'data' => '1' === FacturX::getConfigValue(FacturX::CONFIG_SEND_MAIL, '0'),
                'label' => $this->trans('Mail the invoice to the customer when the order is paid'),
            ])
            ->add(FacturX::CONFIG_REQUIRE_BUYER_VAT, CheckboxType::class, [
                'required' => false,
                'data' => '1' === FacturX::getConfigValue(FacturX::CONFIG_REQUIRE_BUYER_VAT, '0'),
                'label' => $this->trans('Refuse to issue the invoice of a company that gave no VAT number'),
            ])
            ->add(FacturX::CONFIG_SIRET, TextType::class, [
                'required' => false,
                'data' => FacturX::getConfigValue(FacturX::CONFIG_SIRET, ''),
                'label' => $this->trans('SIRET, when the store settings have none'),
                'constraints' => [
                    new Regex([
                        'pattern' => '/^(\d{14})?$/',
                        'message' => $this->trans('The SIRET must be exactly 14 digits'),
                    ]),
                ],
            ])
            ->add(FacturX::CONFIG_TVA_INTRACOM, TextType::class, [
                'required' => false,
                'data' => FacturX::getConfigValue(FacturX::CONFIG_TVA_INTRACOM, ''),
                'label' => $this->trans('Intra-community VAT number, when the store settings have none'),
                'constraints' => [
                    new Regex([
                        'pattern' => '/^([A-Z]{2}[A-Z0-9]{2,13})?$/',
                        'message' => $this->trans('Invalid intra-community VAT number'),
                    ]),
                ],
            ]);
    }

    public static function getName(): string
    {
        return 'facturx_configuration';
    }
}
