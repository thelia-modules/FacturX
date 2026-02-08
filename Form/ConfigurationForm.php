<?php

declare(strict_types=1);

namespace FacturX\Form;

use FacturX\FacturX;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
        $this->formBuilder
            ->add(
                'facturx_siret',
                TextType::class,
                [
                    'required' => false,
                    'data' => FacturX::getConfigValue(FacturX::CONFIG_SIRET, ''),
                    'label' => $this->trans('SIRET'),
                    'label_attr' => [
                        'for' => 'facturx_siret',
                        'help' => $this->trans('Numéro SIRET de votre entreprise (14 chiffres)'),
                    ],
                    'constraints' => [
                        new Regex([
                            'pattern' => '/^(\d{14})?$/',
                            'message' => $this->trans('Le SIRET doit contenir exactement 14 chiffres'),
                        ]),
                    ],
                ]
            )
            ->add(
                'facturx_tva_intracommunautaire',
                TextType::class,
                [
                    'required' => false,
                    'data' => FacturX::getConfigValue(FacturX::CONFIG_TVA_INTRACOM, ''),
                    'label' => $this->trans('TVA intracommunautaire'),
                    'label_attr' => [
                        'for' => 'facturx_tva_intracommunautaire',
                        'help' => $this->trans('Numéro de TVA intracommunautaire (ex: FR12345678901)'),
                    ],
                    'constraints' => [
                        new Regex([
                            'pattern' => '/^([A-Z]{2}\d{2,13})?$/',
                            'message' => $this->trans('Format de TVA intracommunautaire invalide'),
                        ]),
                    ],
                ]
            )
            ->add(
                'facturx_is_enabled',
                CheckboxType::class,
                [
                    'required' => false,
                    'data' => '1' === FacturX::getConfigValue(FacturX::CONFIG_IS_ENABLED, '0'),
                    'label' => $this->trans('Activer Factur-X'),
                    'label_attr' => [
                        'for' => 'facturx_is_enabled',
                        'help' => $this->trans('Activer la génération automatique de factures Factur-X'),
                    ],
                ]
            );
    }

    public static function getName(): string
    {
        return 'facturx_configuration';
    }
}
