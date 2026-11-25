Security regression testing framework for SugarAI (and any HTTP application).

## Building PHAR

phar is built using [Box](https://github.com/box-project/). Once box is installed, the phar can be built using
the following command from the project directory:

```
box build
```

## Installation

As a Composer dev dependency (recommended):

```
composer require --dev sugarcrm/regression:^0.3
```

This provides `vendor/bin/regression` and `vendor/bin/regression-platforms`.

The phar is still attached to releases (unsigned). With phive: `phive install --force-accept-unsigned sugarcrm/regression`.

> Upgrading from `dzentota/regression` 0.2.x: the package was renamed. 0.3.0 requires Guzzle 7, runs
> `setUp()`/`tearDown()` and cleans up the data scenarios create (see below).

## Usage

```
vendor/bin/regression <base_uri> -d tests/security -c admin:<password> [-c sally:<password> ...]
```

Common options (`--help` for all):

| Option | Purpose |
|---|---|
| `-d`, `-f`, `-t` | tests directory / single files / `regression` or `assessment` only |
| `-c user:password` | credentials (default: `admin:asdf`, other users: password = user name) |
| `-p N` | run on N parallel workers (see [Parallel runs](#parallel-runs)) |
| `--failed_steps`, `--failed_scenarios`, `--debug` | request/response output |
| `--report-json FILE` | machine-readable results, including per-test outcome and duration |
| `--timings FILE` | previous report: run the longest scenarios first |
| `--keep-data` | don't run cleanups (debugging) |
| `--run-id ID` | tag for data created by the run (default: random) |
| `--timeout S` | HTTP request timeout (default 120) |
| `--user-pool user:password` | pre-provisioned users for `createUser()` on IdM instances |
| `-l LICENSE_KEY` | apply license key(s) before running |

## Isolation and cleanup

Every scenario runs `setUp()` → `run()` → deferred cleanups (LIFO) → `tearDown()`. Cleanups and
`tearDown()` always run, even when the scenario fails; their errors are reported as warnings with the
run ID and never change the scenario's outcome.

```php
protected function setUp(): void { /* prepare state */ }
protected function tearDown(): void { /* revert state */ }

$this->defer(fn() => $this->cleanupAs('admin')->apiCall("/Foo/$id", 'DELETE'), 'delete Foo');
$name = $this->uniqueName('account'); // account_<runId>_<pid>_<n>
```

Done automatically for SugarAI scenarios:

- records created via REST (`POST /<Module>`, `POST /<Module>/<id>/link/<rel>`) are deleted as admin
  (falling back to the BWC Delete action),
- ACL roles created with `createRole()` are deleted (`deferDelete('ACLRoles', $id)` for others),
- the first `PUT /me/preferences` of a user snapshots their preferences and restores them afterwards.

### Dedicated users

Don't change roles, teams or preferences of shared demo users: other scenarios use them too.

```php
$user = $this->createUser(roles: [$role], like: 'sally'); // copies sally's teams, manager, licenses
$this->loginAs($user);
$id = $this->getUserId($user);
$admin = $this->createUser(isAdmin: true);
```

Users are created via `POST /Users` and deleted after the scenario. On IdM instances (detected the same
way `login()` does) users can't be created locally: pass pre-provisioned users with `--user-pool`;
roles/teams/manager applied to them are reverted afterwards.

### Licenses

```php
#[RequiresLicense('SUGAR_SERVE')]   // skipped unless the instance has one of the listed license types
```

`$this->hasLicense('SUGAR_SELL')` / `getInstanceLicenses()` for conditional logic.

## Parallel runs

Sugar keeps one OAuth token per user and API platform, so each worker logs in on its own platform
(`regression_w<k>`). Install them once per instance:

```
vendor/bin/regression-platforms install <base_uri> -c admin:<password> -w 8   # also: status, uninstall
vendor/bin/regression <base_uri> -d tests/security -c admin:<password> -p 4
```

Scenarios are scheduled by resource locks detected from their source:

- logging in as a user is a read lock on that user; assigning roles to it or changing its preferences is
  a write lock,
- package installs, workflows, global configuration and live Studio changes make a scenario exclusive:
  exclusive scenarios run at the end, alone,
- explicit markers override detection:

```php
#[Exclusive('installs a package')]   // nothing else runs at the same time
#[Uses('packages')]                  // never together with other scenarios using 'packages'
#[Parallel]                          // not exclusive despite what auto-detection thinks
```

Scenarios failing while others ran concurrently are re-run alone; if they pass, they're reported as
undetected interference so that the culprit can be marked.

## Documentation

To write a regression test:
- create a new `class` that extends `\Regression\Scenario`
- implement `getRegressionDescription()` method that describes possible regression
- implement `run()` method that contains all the test logic

In your regression tests you should ensure that when you are sending a predefined set of `Requests`
you get **expected** `Responses`

Let's create a regression test for example.com

```php
<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\Scenario;

class ExampleRegression extends Scenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Response does not fit our expectations';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/'
        );

        $this->send($request)
            ->expectStatusCode(200)
            ->expectSubstring('Example Domain');
    }
}

```
put this file into `tests/regression/ExampleRegression.php`

run regression tests

> regression.phar -d tests/regression https://example.com

Expected output:
```
Running Regression Tests...
===========================

1/1 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 --------- --- 
Total     1  
Success   1  
Failed    0
 --------- --- 


[OK] No issues found                                                                                                   
```

Some functionality in your app may be accessible only for authenticated users. If you need
to send additional information with **each** request to proof that you are logged in you may
implement a custom `session` class that implements `\Regression\Session` interface. E.g for 
OAuth authentication

```php
<?php
declare(strict_types=1);

namespace Regression;

use Psr\Http\Message\RequestInterface;

class OAuthSession implements Session
{
    private string $accessToken;

    public function __construct(string $token)
    {
        $this->accessToken = $token;
    }

    public function init(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('OAuth-Token', $this->accessToken);
    }
}
```

Then you need to init this session right after the authentication. You may add a new method into your scenario
for convenience:

```php
<?php
declare(strict_types=1);

namespace Regression;

use GuzzleHttp\Psr7;

abstract class OAuthScenario extends Scenario
{
    public function login(string $username, string $password): self
    {
        $payload = json_encode([
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]);

        $tokenRequest = new Psr7\Request(
            'POST',
            '/oauth2/token',
            ['Content-Type' => 'application/json'],
            $payload
        );
        $this->send($tokenRequest);
        if (($token = (json_decode((string)$this->lastResponse->getBody()))->access_token) === null) {
            throw new \RuntimeException("Login failed");
        }
        $this->session = new OAuthSession($token);
        return $this;
    }
}
```
