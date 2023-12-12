<?php

namespace Regression\Helpers\Output;

use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Style\OutputStyle;

class ResponseOutputItem extends QueueOutputItem
{
    private ResponseInterface $response;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function execOutput(OutputStyle $style): callable
    {
        return function () use ($style) {
            $style->title('Response');
            $style->section(
                Message::toString($this->response)
            );
        };
    }
}