#!/usr/bin/env php
<?php

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Regression\Webserver\Server;

require 'vendor/autoload.php';

array_shift($argv);

if (empty($argv)) {
    $port = rand(8000, 9999);
} else {
    $port = array_shift($argv);
}
echo "Start listening on 127.0.0.1:{$port}", PHP_EOL;

$server = new Server('127.0.0.1', $port);

$server->listen(function (Request $request) {
    echo $request->getMethod() . ' ' . $request->getUri() . "\n";
    // Just output the received Request
    return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], var_export($request, true));
});