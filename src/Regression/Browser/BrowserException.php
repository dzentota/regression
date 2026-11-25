<?php
declare(strict_types=1);

namespace Regression\Browser;

/**
 * The browser couldn't run (helper missing, page not loadable...). Not evidence of a vulnerability.
 */
final class BrowserException extends \RuntimeException
{
}
