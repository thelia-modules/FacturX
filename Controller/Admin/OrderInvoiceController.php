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

use FacturX\Service\Configuration;
use FacturX\Service\FacturXGenerator;
use FacturX\Service\GenerationLimiter;
use FacturX\Service\InvoiceArchive;
use FacturX\Service\InvoiceNumberResolver;
use FacturX\Service\InvoiceRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderQuery;
use Thelia\Tools\TokenProvider;

#[AsController]
final class OrderInvoiceController extends BaseAdminController
{
    private const DETAIL_ROUTE = 'admin.order.update.view';

    #[Route('/admin/module/FacturX/order/{order_id}/download', name: 'facturx.order.download', requirements: ['order_id' => '\d+'], methods: ['GET'])]
    public function download(int $order_id, InvoiceRenderer $renderer, InvoiceNumberResolver $numberResolver, FacturXGenerator $generator, Configuration $configuration, InvoiceArchive $archive, GenerationLimiter $limiter, UrlGeneratorInterface $urls): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return $response;
        }

        $order = OrderQuery::create()->findPk($order_id);
        $number = null === $order ? null : $numberResolver->resolve($order);
        if (null === $order || null === $number) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // An issued invoice is served as it was archived: the settings of today do not apply to
        // it, and serving it generates nothing.
        $archived = $configuration->isEnabled() && null !== $order->getInvoiceDate() ? $archive->find($number, $order->getInvoiceDate()) : null;
        if (null !== $archived) {
            return $this->pdf($archived, $number);
        }

        if ($configuration->isEnabled() && !$generator->report($order)->isComplete()) {
            return new RedirectResponse($urls->generate(self::DETAIL_ROUTE, ['order_id' => $order_id]));
        }

        if (!$limiter->allows($this->getSecurityContext()->getAdminUser()->getId())) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->pdf($renderer->preview($order), $number);
    }

    #[Route('/admin/module/FacturX/order/{order_id}/generate', name: 'facturx.order.generate', requirements: ['order_id' => '\d+'], methods: ['POST'])]
    public function generate(int $order_id, Request $request, InvoiceRenderer $renderer, FacturXGenerator $generator, GenerationLimiter $limiter, TokenProvider $tokens, UrlGeneratorInterface $urls): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }

        $back = new RedirectResponse($urls->generate(self::DETAIL_ROUTE, ['order_id' => $order_id]));

        try {
            $tokens->checkRequestToken($request);
        } catch (\Throwable) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $order = OrderQuery::create()->findPk($order_id);
        if (null === $order) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$limiter->allows($this->getSecurityContext()->getAdminUser()->getId())) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($generator->report($order)->isComplete()) {
            $renderer->render($order);
        }

        return $back;
    }

    private function pdf(string $content, string $number): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('attachment; filename="invoice-%s.pdf"', preg_replace('/[^A-Za-z0-9._-]/', '_', $number)),
        ]);
    }
}
