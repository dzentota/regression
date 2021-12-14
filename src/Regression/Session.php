<?php
declare(strict_types=1);

namespace Regression;

use Psr\Http\Message\RequestInterface;

interface Session
{
    public function init(RequestInterface $request): RequestInterface;
}
