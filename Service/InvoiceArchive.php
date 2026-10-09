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

use FacturX\Domain\Profile;

final readonly class InvoiceArchive
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    public function find(string $number, \DateTimeInterface $issueDate): ?string
    {
        $path = $this->path($number, $issueDate);

        if (!is_file($path) || !is_file($path.'.sha256')) {
            return null;
        }

        $content = (string) file_get_contents($path);

        if (!hash_equals(trim((string) file_get_contents($path.'.sha256')), hash('sha256', $content))) {
            throw new \RuntimeException(\sprintf('The archived invoice %s does not match its recorded fingerprint.', $number));
        }

        return $content;
    }

    public function store(string $number, \DateTimeInterface $issueDate, string $content, Profile $profile): void
    {
        $path = $this->path($number, $issueDate);
        $this->ensureDirectory($path);

        $this->writeOnce($path, $content);
        $this->writeOnce($path.'.sha256', hash('sha256', $content));
        $this->writeOnce($path.'.profile', $profile->value);
    }

    public function profile(string $number, \DateTimeInterface $issueDate): ?Profile
    {
        $path = $this->path($number, $issueDate).'.profile';

        return is_file($path) ? Profile::tryFrom(trim((string) file_get_contents($path))) : null;
    }

    public function markMailed(string $number, \DateTimeInterface $issueDate): void
    {
        $path = $this->path($number, $issueDate).'.mailed';
        $this->ensureDirectory($path);

        file_put_contents($path, (new \DateTimeImmutable())->format(\DATE_ATOM));
    }

    public function wasMailed(string $number, \DateTimeInterface $issueDate): bool
    {
        return is_file($this->path($number, $issueDate).'.mailed');
    }

    public function path(string $number, \DateTimeInterface $issueDate): string
    {
        return \sprintf(
            '%s/%s/%s.pdf',
            $this->configuration->storagePath(),
            $issueDate->format('Y'),
            preg_replace('/[^A-Za-z0-9._-]/', '_', $number),
        );
    }

    private function ensureDirectory(string $path): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $directory));
        }
    }

    private function writeOnce(string $path, string $content): void
    {
        if (is_file($path)) {
            return;
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, $content);
        rename($temporary, $path);
    }
}
