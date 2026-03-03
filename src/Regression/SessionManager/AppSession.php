<?php

namespace Regression\SessionManager;

use Regression\Session;

class AppSession
{
    private Session $appSession;

    private ?string $bwcSessionCookie;

    private bool $needReLogin = false;

    public function __construct(Session $appSession, string $bwcSessionCookie = null)
    {
        $this->appSession = $appSession;
        $this->bwcSessionCookie = $bwcSessionCookie;
    }

    public function getAppSession(): Session
    {
        return $this->appSession;
    }

    public function getBwcSessionCookie(): string
    {
        return $this->bwcSessionCookie;
    }

    public function setBwcSessionCookie(string $bwcSessionCookie): self
    {
        $this->bwcSessionCookie = $bwcSessionCookie;

        return $this;
    }

    public function updateReLoginState(bool $state): void
    {
        $this->needReLogin = $state;
    }

    public function isReLoginNeeded(): bool
    {
        return $this->needReLogin;
    }
}