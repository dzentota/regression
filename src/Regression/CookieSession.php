<?php
declare(strict_types=1);

namespace Regression;

use Psr\Http\Message\RequestInterface;

/**
 * Session implementation that authenticates via a Cookie header.
 * Used when browser-based navigation requires a PHP session cookie
 * rather than an OAuth bearer token.
 */
class CookieSession implements Session
{
    public function __construct(private readonly string $cookieHeader)
    {
    }

    public function init(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Cookie', $this->cookieHeader);
    }
}
