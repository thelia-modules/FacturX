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

namespace FacturX\Twig;

use FacturX\FacturX;
use Thelia\Core\Translation\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class TranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('facturx_trans', $this->translate(...), ['needs_context' => true]),
        ];
    }

    public function translate(array $context, ?string $id, array $parameters = []): string
    {
        $locale = $context['invoice_locale'] ?? null;

        return $this->translator->trans(
            (string) $id,
            $parameters,
            FacturX::MODULE_DOMAIN,
            \is_string($locale) && '' !== $locale ? $locale : null,
        );
    }
}
