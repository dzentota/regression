<?php

namespace Regression\SessionManager;

class SessionStorage
{
    /** @var AppSession[] */
    private array $activeSessions = [];

    public function getSession(string $login): ?AppSession
    {
        return $this->activeSessions[$login] ?? null;
    }

    public function initSession(string $login, AppSession $session): void
    {
        $this->activeSessions[$login] = $session;
    }

    public function removeSession(string $login): void
    {
        if (isset($this->activeSessions[$login])) {
            unset($this->activeSessions[$login]);
        }
    }
}