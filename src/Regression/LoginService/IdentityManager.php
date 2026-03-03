<?php

namespace Regression\LoginService;

use Regression\SessionManager\AppSession;
use Regression\SessionManager\SessionStorage;
use Regression\Session;
use Regression\SugarSession;

class IdentityManager
{
    private const int UNAUTHENTICATED = 401;

    private SessionStorage $sessionStorage;

    private ?AppSession $activeSession = null;
    private bool $isIdm = false;

    public function __construct(SessionStorage $sessionStorage)
    {
        $this->sessionStorage = $sessionStorage;
    }

    public function switchActiveSession(
        string $login,
        callable $pingStatusCodeCallback,
        callable $loginCallback,
        callable $bwcCallback = null,
    ): void
    {
        $this->activeSession = $this->sessionStorage->getSession($login);

        if (
            !$this->activeSession ||
            $pingStatusCodeCallback() === self::UNAUTHENTICATED ||
            $this->activeSession->isReLoginNeeded()
        ) {
            $appToken = $loginCallback();

            $session = new AppSession(new SugarSession($appToken));
            $this->sessionStorage->initSession($login, $session);
            $this->activeSession = $session;

            if ($bwcCallback) {
                $this->activeSession->setBwcSessionCookie($bwcCallback());
            }
        }
    }

    public function getActiveSession(): ?AppSession
    {
        return $this->activeSession;
    }
}