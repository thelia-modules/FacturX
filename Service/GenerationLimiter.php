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

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final readonly class GenerationLimiter
{
    private RateLimiterFactory $perAdministrator;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
    ) {
        $this->perAdministrator = new RateLimiterFactory([
            'id' => 'facturx_generation_per_administrator',
            'policy' => 'sliding_window',
            'limit' => 30,
            'interval' => '15 minutes',
        ], new CacheStorage($cache));
    }

    public function allows(int $administratorId): bool
    {
        return $this->perAdministrator->create('administrator-'.$administratorId)->consume()->isAccepted();
    }
}
