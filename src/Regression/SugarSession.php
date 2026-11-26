<?php
declare(strict_types=1);

namespace Regression;

use Psr\Http\Message\RequestInterface;

class SugarSession implements Session
{
    private string $accessToken;

    public function __construct(string $token, private ?string $refreshToken = null)
    {
        $this->accessToken = $token;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function init(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('OAuth-Token', $this->accessToken);
    }
}
