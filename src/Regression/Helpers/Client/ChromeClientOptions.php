<?php

namespace Regression\Helpers\Client;


/**
 * @property bool $leaveOpened
 * @property string $pageLoadingStep
 * @property int|null $pageLoadingTimeout
 * @property bool $skipInterception
 */
class ChromeClientOptions
{
    public function __construct(
        public bool $leaveOpened = false,
        public string $pageLoadingStep = 'load', // HeadlessChromium\Page::LOAD (chrome-php is optional)
        public ?int $pageLoadingTimeout = null,
        public bool $skipInterception = false
    ) {
    }
}