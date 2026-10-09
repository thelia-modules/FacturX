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

use Atgp\FacturX\Reader;
use Atgp\FacturX\Writer;
use FacturX\Domain\CompletenessReport;
use FacturX\Domain\IncompleteInvoiceException;
use FacturX\Domain\InvoiceData;
use Thelia\Model\Order;

final readonly class FacturXGenerator
{
    public function __construct(
        private InvoiceDataBuilder $dataBuilder,
        private CompletenessChecker $completenessChecker,
        private CiiXmlBuilder $xmlBuilder,
        private PdfANormalizer $normalizer,
        private Configuration $configuration,
    ) {
    }

    public function data(Order $order): InvoiceData
    {
        return $this->dataBuilder->build($order);
    }

    public function report(Order $order): CompletenessReport
    {
        return $this->completenessChecker->check(
            $this->dataBuilder->build($order),
            $this->configuration->profile(),
            $this->configuration->requiresBuyerVatNumber(),
        );
    }

    public function generate(Order $order, string $pdf): string
    {
        $profile = $this->configuration->profile();
        $data = $this->dataBuilder->build($order);

        $report = $this->completenessChecker->check($data, $profile, $this->configuration->requiresBuyerVatNumber());
        if (!$report->isComplete()) {
            throw new IncompleteInvoiceException($report);
        }

        $xml = $this->xmlBuilder->build($data, $profile);

        if ($this->normalizer->isAvailable()) {
            $pdf = $this->normalizer->normalize($pdf);
        }

        $facturX = (new Writer())->generate($pdf, $xml, $profile->value, true);

        if (trim((new Reader())->extractXML($facturX)) !== trim($xml)) {
            throw new \RuntimeException('The structured invoice read back from the PDF differs from the one written.');
        }

        return $facturX;
    }
}
