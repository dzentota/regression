<?php

namespace Regression\Helpers\Output;

use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Style\OutputStyle;

class RequestOutputItem extends QueueOutputItem
{
    private RequestInterface $request;
    private array $options;

    public function __construct(RequestInterface $request, array $options = [])
    {
        $this->request = $request;
        $this->options = $options;
    }

    public function execOutput(OutputStyle $style): callable
    {
        return function () use ($style) {
            $style->title('Request to ' . $this->request->getUri());
            $style->section(
                Message::toString($this->request)
            );
            if (count($this->options)) {
                $style->section(var_export($this->options, true));
            }
        };
    }
}