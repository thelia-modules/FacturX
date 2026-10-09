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

namespace FacturX\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RequestContext;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Tools\URL;

readonly class RenderingRequest
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function during(Lang $lang, callable $work): mixed
    {
        if (null !== $this->requestStack->getCurrentRequest()) {
            return $work();
        }

        $request = Request::create($this->baseUrl($lang));
        $session = new Session(new MockArraySessionStorage());
        $session->setLang($lang);
        $request->setSession($session);

        $context = new RequestContext();
        $context->fromRequest($request);
        URL::getInstance()->setRequestContext($context);

        $this->requestStack->push($request);

        try {
            return $work();
        } finally {
            $this->requestStack->pop();
        }
    }

    private function baseUrl(Lang $lang): string
    {
        $baseUrl = 1 === (int) ConfigQuery::read('one_domain_foreach_lang') ? trim((string) $lang->getUrl()) : '';

        if ('' === $baseUrl || '0' === $baseUrl) {
            $baseUrl = (string) ConfigQuery::read('url_site');
        }

        return '' === $baseUrl ? 'http://localhost' : $baseUrl;
    }
}
