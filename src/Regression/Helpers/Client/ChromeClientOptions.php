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
    private bool $leaveOpened = false;

    private string $pageLoadingStep = Page::LOAD;

    private ?int $pageLoadingTimeout = null;

    private bool $skipInterception = false;

    public function __get(string $name)
    {
        $this->ensurePropertyExists($name);

        return $this->$name;
    }

    public function __set(string $name, $value)
    {
        $this->ensurePropertyExists($name);

        $this->$name = $value;
    }

    private function ensurePropertyExists(string $name): void
    {
        if (!property_exists($this, $name)) {
            throw new \Exception("Chrome client options don't contain property $name");
        }
    }
}