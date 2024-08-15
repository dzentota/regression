<?php
declare(strict_types=1);

namespace Regression\Adapter;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Regression\Client\Chrome;
use Regression\Client\ClientInterface;

trait HeadlessBrowser
{
    private Browser $browser;

    private Page $mainPage;

    private function initBrowser(): array
    {
        $options = [
            'headless' => true,
            'windowSize' => [1920, 1080],
            'enableImages' => false,
            'ignoreCertificateErrors' => true,
            'keepAlive' => true,
        ];
        $browserFactory = new BrowserFactory();

        $socketFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chrome-php-regression-socket';

        if (!(is_file($socketFilePath) && is_readable($socketFilePath))) {
            touch($socketFilePath);
        }

        try {
            $socket = file_get_contents($socketFilePath);

            $this->browser = $browserFactory::connectToBrowser($socket, $options);
        } catch (\Throwable $e) {
            $this->browser = $browserFactory->createBrowser($options);

            file_put_contents($socketFilePath, $this->browser->getSocketUri(), LOCK_EX);
        }

        return $options;
    }

    protected function initClient(): ClientInterface
    {
        $options = $this->initBrowser();

        return new Chrome($this->browser, $options, $this->config->getBaseUri());
    }

    public function __destruct()
    {
        $this->browser->close();
    }

    protected function getLastOpenedPage(): Page
    {
        $pageId = Chrome::getLastOpenedPage();

        if (!$pageId) {
            throw new \Exception('No opened pages was found in Chrome. If you want to leave page as opened then add flag "leaveOpened" => true to options.');
        }

        return $this->browser->getPage($pageId);
    }
}