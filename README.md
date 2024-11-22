The simple PHP regression testing framework.

## Building PHAR

phar is built using [Box](https://github.com/box-project/). Once box is installed, the phar can be built using
the following command from the project directory:

```
box build
```

## Installation

### Use `phive` https://phar.io/
> phive install dzentota/regression

or add dzentota/regression to .phive/phars.xml e.g

```xml
<?xml version="1.0"?>
<phive xmlns="https://phar.io/phive">
  <phar name="dzentota/regression" version="^0.2.4" installed="0.1.0" location="./vendor/bin/regression.phar" copy="true"/>
</phive>
```

## Usage

> regression.phar [options] [--] <base_uri>

```
Arguments:

base_uri                     The base uri of your application

Options:
-d, --tests_dir[=TESTS_DIR]  The directory where your tests are placed [default: "./tests"]
--debug                      Show detailed info about requests and responses
-h, --help                   Display help for the given command. When no command is given display help for the ./bin/regression command
-q, --quiet                  Do not output any message
-V, --version                Display this application version
--ansi|--no-ansi             Force (or disable --no-ansi) ANSI output
-n, --no-interaction         Do not ask any interactive question
-v|vv|vvv, --verbose         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```
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
