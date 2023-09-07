<?php
declare(strict_types=1);

namespace Regression;

use Psr\Http\Message\RequestInterface;

class SugarSession implements Session
{
    public readonly string $accessToken;

    public function __construct(string $token)
    {
        $this->accessToken = $token;
    }

    public function init(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('OAuth-Token', $this->accessToken);
    }
}
