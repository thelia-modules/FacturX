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

namespace FacturX\Tests\Unit;

use FacturX\Domain\Profile;
use FacturX\Service\InvoiceArchive;
use FacturX\Tests\Support\StubConfiguration;
use PHPUnit\Framework\TestCase;

final class InvoiceArchiveTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/facturx-archive-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storage)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->storage);
    }

    public function testAStoreAfterAnInterruptedOneLeavesAnArchiveThatCanBeServed(): void
    {
        $archive = new InvoiceArchive(new StubConfiguration($this->storage));
        $issueDate = new \DateTimeImmutable('2026-10-01 10:00:00');
        $path = $archive->path('2026-000042', $issueDate);

        // An earlier store wrote the PDF and stopped before its fingerprint.
        mkdir(\dirname($path), 0o775, true);
        file_put_contents($path, 'first-rendering');

        $archive->store('2026-000042', $issueDate, 'second-rendering', Profile::En16931);

        try {
            $served = $archive->find('2026-000042', $issueDate);
        } catch (\RuntimeException $exception) {
            self::fail('The archive is left unservable: '.$exception->getMessage());
        }

        self::assertNotNull($served);
        self::assertSame(hash('sha256', (string) $served), trim((string) file_get_contents($path.'.sha256')));
    }

    public function testAFinishedStoreIsNeverOverwritten(): void
    {
        $archive = new InvoiceArchive(new StubConfiguration($this->storage));
        $issueDate = new \DateTimeImmutable('2026-10-01 10:00:00');

        $archive->store('2026-000042', $issueDate, 'issued-rendering', Profile::En16931);
        $archive->store('2026-000042', $issueDate, 'later-rendering', Profile::Minimum);

        self::assertSame('issued-rendering', $archive->find('2026-000042', $issueDate));
        self::assertSame(Profile::En16931, $archive->profile('2026-000042', $issueDate));
    }
}
