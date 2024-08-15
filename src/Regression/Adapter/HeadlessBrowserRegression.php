<?php

namespace Regression\Adapter;

use HeadlessChromium\Page;
use Psr\Http\Message\ResponseInterface;
use Regression\Regression;
use Regression\Status;

trait HeadlessBrowserRegression
{
    use HeadlessBrowser;

    abstract protected function throwException(string $message);

    protected ResponseInterface $lastResponse;

    public function expectNoXss(Page $page = null): Regression
    {
        if ($page) {
            $content = $page->getHtml();
        } else {
            $content = (string)$this->lastResponse->getBody();
        }

        if (str_contains($content, 'XSS!!! Detected')) {
            $this->throwException('XSS was found');
        }

        $this->status = Status::NO_ISSUE;
        return $this;
    }
}