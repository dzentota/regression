<?php

namespace Regression\Helpers\Client;

use HeadlessChromium\Page;

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
        public string $pageLoadingStep = Page::LOAD,
        public ?int $pageLoadingTimeout = null,
        public bool $skipInterception = false
    ) {
    }
}