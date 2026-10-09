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

namespace FacturX\Hook;

use FacturX\FacturX;
use FacturX\Form\ConfigurationForm;
use FacturX\Service\PdfANormalizer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;

final class ConfigurationHook extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly PdfANormalizer $normalizer,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
        ];
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $event->add($this->render('FacturX/module_configuration.html.twig', [
            'form' => $this->formFactory->createForm(ConfigurationForm::getName())->createView()->getView(),
            'module_code' => FacturX::getModuleCode(),
            'has_normalizer' => $this->normalizer->isAvailable(),
            'storage_path' => (string) FacturX::getConfigValue(FacturX::CONFIG_STORAGE_PATH),
        ]));
    }
}
