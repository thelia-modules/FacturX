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

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfANormalizer
{
    private const PROFILE_DIRECTORIES = [
        '/usr/share/color/icc/ghostscript',
        '/usr/share/ghostscript/*/iccprofiles',
        '/usr/local/share/ghostscript/*/iccprofiles',
        '/opt/homebrew/share/ghostscript/*/iccprofiles',
    ];

    private ?string $binary = null;
    private ?string $profile = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ?string $binaryPath = null,
        private readonly int $timeoutInSeconds = 60,
    ) {
    }

    public function isAvailable(): bool
    {
        $this->resolve();

        return null !== $this->binary && null !== $this->profile;
    }

    public function normalize(string $pdf): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Ghostscript or its sRGB profile is not available.');
        }

        $input = tempnam(sys_get_temp_dir(), 'facturx-in-');
        $output = tempnam(sys_get_temp_dir(), 'facturx-out-');
        $definition = tempnam(sys_get_temp_dir(), 'facturx-def-');

        try {
            file_put_contents($input, $pdf);
            file_put_contents($definition, $this->definition((string) $this->profile));

            $process = new Process([
                (string) $this->binary,
                '-q',
                '-dNOSAFER',
                '-dPDFA=3',
                '-dWriteObjStms=false',
                '-dWriteXRefStm=false',
                '-dBATCH',
                '-dNOPAUSE',
                '-dNOOUTERSAVE',
                '-sColorConversionStrategy=RGB',
                '-sDEVICE=pdfwrite',
                '-dPDFACompatibilityPolicy=1',
                '-sOutputFile='.$output,
                $definition,
                $input,
            ]);
            $process->setTimeout($this->timeoutInSeconds);
            $process->run();

            $converted = (string) file_get_contents($output);

            if (!$process->isSuccessful() || !str_starts_with($converted, '%PDF')) {
                throw new \RuntimeException(\sprintf('Ghostscript could not convert the PDF to PDF/A-3: %s', trim($process->getErrorOutput())));
            }

            return $converted;
        } finally {
            foreach ([$input, $output, $definition] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;
        $this->binary = '' === $this->binaryPath ? null : ($this->binaryPath ?? (new ExecutableFinder())->find('gs'));

        foreach (self::PROFILE_DIRECTORIES as $pattern) {
            foreach (glob($pattern) ?: [] as $directory) {
                if (is_file($directory.'/default_rgb.icc')) {
                    $this->profile = $directory.'/default_rgb.icc';

                    return;
                }
            }
        }
    }

    private function definition(string $profile): string
    {
        $escaped = addcslashes($profile, '\\()');

        return <<<PS
            %!
            /ICCProfile ($escaped) def
            [/_objdef {icc_PDFA} /type /stream /OBJ pdfmark
            [{icc_PDFA} <</N 3>> /PUT pdfmark
            [{icc_PDFA} ICCProfile (r) file /PUT pdfmark
            [/_objdef {OutputIntent_PDFA} /type /dict /OBJ pdfmark
            [{OutputIntent_PDFA} <</Type /OutputIntent /S /GTS_PDFA1 /DestOutputProfile {icc_PDFA} /OutputConditionIdentifier (sRGB) /Info (sRGB)>> /PUT pdfmark
            [{Catalog} <</OutputIntents [ {OutputIntent_PDFA} ]>> /PUT pdfmark

            PS;
    }
}
