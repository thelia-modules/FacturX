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

require dirname(__DIR__, 4).'/tests/bootstrap.php';

$library = getenv('FACTURX_LIBRARY_AUTOLOAD');

if (is_string($library) && is_file($library)) {
    require_once $library;
}
