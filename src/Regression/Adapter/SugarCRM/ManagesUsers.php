<?php
declare(strict_types=1);

namespace Regression\Adapter\SugarCRM;

/**
 * Per-scenario users, so ACL tests don't collide on shared demo users' roles/teams.
 *
 * Primary strategy: create a user via POST /Users with `skip_idm_mode_restrictions` and a plaintext
 * `user_hash` (hashed by SugarFieldPassword::apiSave), delete it after the scenario.
 *
 * Fallback (IdM instances, detected like login() does via /ping, where locally created users cannot
 * authenticate): lease a pre-provisioned
 * user from the pool (--user-pool), apply roles/teams/reports_to to it and revert them afterwards.
 * In parallel runs worker k only leases pool users with index % workers == k - 1.
 *
 * Admin operations run on a separate client; the scenario's own session is left untouched.
 */
trait ManagesUsers
{
    /** @var array<string, true> pool users leased in this process */
    private static array $leasedPoolUsers = [];

    private static ?array $defaultLicenseTypes = null;

    /** @var array<string, string> user_name => id of users created/leased by this scenario */
    private array $userIds = [];

    /**
     * @param array<Role|string> $roles Role objects or role ids
     * @param string[] $teams team ids or names
     * @param string|null $reportsTo user_name of the manager
     * @param string[]|null $licenseTypes default: admin's license types
     * @param array $fields any other Users fields
     * @param string|null $like user_name of an existing user whose teams, manager and license types are copied
     *                          (e.g. 'sally'), so migrated tests keep the same record visibility
     * @return string user_name to pass to loginAs()
     */
    public function createUser(
        array $roles = [],
        array $teams = [],
        ?string $reportsTo = null,
        ?array $licenseTypes = null,
        bool $isAdmin = false,
        array $fields = [],
        ?string $like = null,
    ): string {
        return $this->asAdmin(function () use ($roles, $teams, $reportsTo, $licenseTypes, $isAdmin, $fields, $like) {
            $teamIds = array_map(fn(string $team) => $this->resolveId('Teams', 'name', $team), $teams);
            $managerId = $reportsTo !== null ? $this->resolveId('Users', 'user_name', $reportsTo) : null;
            if ($like !== null) {
                $template = $this->resolveId('Users', 'user_name', $like);
                $this->apiCall("/Users/$template?fields=reports_to_id,license_type");
                $data = $this->json();
                $managerId ??= ($data['reports_to_id'] ?? '') ?: null;
                $licenseTypes ??= $data['license_type'] ?? null;
                $this->apiCall("/Users/$template/link/team_memberships?fields=id,private&max_num=100");
                foreach ($this->json()['records'] ?? [] as $team) {
                    if (empty($team['private'])) {
                        $teamIds[] = $team['id'];
                    }
                }
                $teamIds = array_values(array_unique($teamIds));
            }

            if ($this->isIdmMode()) {
                return $this->leasePoolUser($roles, $teamIds, $managerId);
            }
            $user = $this->createLocalUser($teamIds, $managerId, $licenseTypes, $isAdmin, $fields);
            $this->userIds[$user['user_name']] = $user['id'];
            $this->assignRoles($user['id'], $roles);
            return $user['user_name'];
        });
    }

    /**
     * @return array{id: string, user_name: string}
     */
    private function createLocalUser(array $teamIds, ?string $managerId, ?array $licenseTypes, bool $isAdmin, array $fields): array
    {
        $userName = $this->uniqueName('rg');
        $password = 'Rg#' . bin2hex(random_bytes(6)) . 'x9';
        $payload = $fields + [
            'user_name' => $userName,
            'first_name' => 'Regression',
            'last_name' => $userName,
            'user_hash' => $password,
            'status' => 'Active',
            'is_admin' => $isAdmin,
            'license_type' => $licenseTypes ?? $this->defaultLicenseTypes(),
            'skip_idm_mode_restrictions' => true,
        ];
        if (!empty($teamIds)) {
            $payload['team_name'] = array_map(
                fn(string $id, int $i) => ['id' => $id, 'primary' => $i === 0],
                $teamIds,
                array_keys($teamIds)
            );
        }
        if ($managerId !== null) {
            $payload['reports_to_id'] = $managerId;
        }
        $this->apiCall('/Users', 'POST', $payload);
        $body = $this->json();
        if ($this->getLastResponse()->getStatusCode() !== 200 || empty($body['id'])) {
            throw new \RuntimeException('Cannot create user: ' . $this->getLastResponse()->getStatusCode() . ' ' . substr(json_encode($body), 0, 300));
        }
        // Deletion is registered by trackCreatedRecords(); deferDelete() adds the IdM skip flag for Users.
        $this->config->set('credentials', [$userName => $password] + (array)$this->config->get('credentials', []));
        return ['id' => $body['id'], 'user_name' => $userName];
    }

    private function leasePoolUser(array $roles, array $teamIds, ?string $managerId): string
    {
        $pool = (array)$this->config->get('userPool', []);
        $workers = max(1, (int)$this->config->get('workers', 1));
        $worker = max(1, (int)$this->config->get('worker', 1));
        $index = 0;
        $candidate = null;
        foreach ($pool as $userName => $password) {
            if ($index++ % $workers === $worker - 1 && !isset(self::$leasedPoolUsers[$userName])) {
                $candidate = $userName;
                break;
            }
        }
        if ($candidate === null) {
            throw new \RuntimeException(
                'Cannot create users that can log in (IdM mode?) and no free pool user for this worker. '
                . 'Provision users and pass them with --user-pool user:password (at least one per worker).'
            );
        }
        self::$leasedPoolUsers[$candidate] = true;
        $this->config->set('credentials', [$candidate => $pool[$candidate]] + (array)$this->config->get('credentials', []));
        $userId = $this->resolveId('Users', 'user_name', $candidate);
        $this->userIds[$candidate] = $userId;

        $this->defer(function () use ($candidate) {
            unset(self::$leasedPoolUsers[$candidate]);
        }, "release pool user $candidate");

        $this->assignRoles($userId, $roles);
        foreach ($teamIds as $teamId) {
            $this->apiCall("/Teams/$teamId/link/users/$userId", 'POST');
            $this->defer(fn() => $this->cleanupAs('admin')->apiCall("/Teams/$teamId/link/users/$userId", 'DELETE'), "remove $candidate from team $teamId");
        }
        if ($managerId !== null) {
            $this->apiCall("/Users/$userId?fields=reports_to_id");
            $previous = $this->json()['reports_to_id'] ?? '';
            $this->apiCall("/Users/$userId", 'PUT', ['reports_to_id' => $managerId, 'skip_idm_mode_restrictions' => true]);
            $this->defer(fn() => $this->cleanupAs('admin')->apiCall("/Users/$userId", 'PUT', ['reports_to_id' => $previous, 'skip_idm_mode_restrictions' => true]), "restore reports_to of $candidate");
        }
        return $candidate;
    }

    /**
     * Id of a user created by createUser() (or of any existing user).
     */
    public function getUserId(string $userName): string
    {
        return $this->userIds[$userName] ??= $this->asAdmin(fn() => $this->resolveId('Users', 'user_name', $userName));
    }

    /**
     * @param array<Role|string> $roles
     */
    private function assignRoles(string $userId, array $roles): void
    {
        foreach ($roles as $role) {
            $roleId = $role instanceof Role ? $role->getId() : $role;
            $this->apiCall("/Users/$userId/link/aclroles/$roleId", 'POST');
            if ($this->getLastResponse()->getStatusCode() !== 200) {
                throw new \RuntimeException("Cannot assign role $roleId to user $userId");
            }
            // Removing the link matters for pool users; for created users the user deletion covers it.
            $this->defer(fn() => $this->cleanupAs('admin')->apiCall("/Users/$userId/link/aclroles/$roleId", 'DELETE'), "unassign role $roleId");
        }
    }

    /**
     * Runs $callback logged in as admin on a separate client, then restores the scenario's session.
     */
    private function asAdmin(callable $callback): mixed
    {
        $saved = [$this->client ?? null, $this->session ?? null, $this->currentUser, $this->currentUserSession];
        $savedResponse = [$this->lastRequest ?? null, $this->lastResponse ?? null];
        try {
            $this->client = $this->initClient();
            $this->session = null;
            $this->loginAs('admin');
            return $callback();
        } finally {
            [$client, $this->session, $this->currentUser, $this->currentUserSession] = $saved;
            if ($client !== null) {
                $this->client = $client;
            } else {
                unset($this->client);
            }
            if ($savedResponse[0] !== null) {
                [$this->lastRequest, $this->lastResponse] = $savedResponse;
            }
        }
    }

    private function defaultLicenseTypes(): array
    {
        if (self::$defaultLicenseTypes === null) {
            $this->apiCall('/me');
            $adminId = $this->json()['current_user']['id'] ?? '1';
            $this->apiCall("/Users/$adminId?fields=license_type");
            self::$defaultLicenseTypes = (array)($this->json()['license_type'] ?? []);
        }
        return self::$defaultLicenseTypes;
    }

    private function resolveId(string $module, string $field, string $value): string
    {
        if (preg_match('/^[0-9a-f-]{32,36}$|^\d+$/i', $value)) {
            return $value;
        }
        $this->apiCall("/$module?" . http_build_query(['filter' => [[$field => $value]], 'fields' => 'id', 'max_num' => 1]));
        $id = $this->json()['records'][0]['id'] ?? null;
        if ($id === null) {
            throw new \RuntimeException("$module with $field '$value' not found");
        }
        return $id;
    }

    private function json(): array
    {
        $stream = $this->getLastResponse()->getBody();
        $data = json_decode((string)$stream, true);
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        return is_array($data) ? $data : [];
    }
}
