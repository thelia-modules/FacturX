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

use FacturX\FacturX;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The mailer renders the subject stored in the message with Twig: a subject seeded with the
 * placeholders of a translation (`%invoice%`) reaches the customer as it was written.
 */
final class InvoiceMailSubjectTest extends IntegrationTestCase
{
    public function testTheSeededSubjectIsRenderedWithTheNumberAndTheOrderInEveryLanguage(): void
    {
        $twig = new Environment(new ArrayLoader());

        foreach (['en_US' => 'Your invoice 2026-000042 for order ORD000000000007', 'fr_FR' => 'Votre facture 2026-000042 pour la commande ORD000000000007'] as $locale => $expected) {
            $rendered = $twig->createTemplate(FacturX::mailSubject($locale))->render(['invoice_ref' => '2026-000042', 'order_ref' => 'ORD000000000007']);

            self::assertSame($expected, $rendered, $locale);
        }
    }
}
