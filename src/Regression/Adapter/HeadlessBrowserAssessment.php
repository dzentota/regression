<?php

namespace Regression\Adapter;

use HeadlessChromium\Page;
use Regression\Assessment;

trait HeadlessBrowserAssessment
{
    use HeadlessBrowser;

    public function assumeNoXss(?Page $page, &$result): Assessment
    {
        if ($page) {
            $content = $page->getHtml();
        } else {
            $content = (string)$this->lastResponse->getBody();
        }

        $result = false === strpos($content, 'XSS!!! Detected');

        return $this;
    }
}