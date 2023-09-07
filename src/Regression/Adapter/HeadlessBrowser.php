<?php
declare(strict_types=1);

namespace Regression\Adapter;

use HeadlessChromium\BrowserFactory;
use Regression\Client\Chrome;
use Regression\Client\ClientInterface;

trait HeadlessBrowser
{
    protected function initClient(): ClientInterface
    {
        $options = $this->getOptions();
        $browserFactory = new BrowserFactory();
        return new Chrome($browserFactory, $options, $this->baseUri);
    }

    protected function getOptions(): array
    {
        return [
            'headless' => true,
            'windowSize' => [1920, 1080],
            'enableImages' => false,
            'ignoreCertificateErrors' => true,
            'debugLogger'     => 'php://stdout'
        ];
    }
}