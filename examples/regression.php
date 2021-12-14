<?php

use Monolog\Logger;
use Regression\RegressionException;

require 'vendor/autoload.php';

$customRecursiveIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ .'/tests'));
$customRegexIterator = new RegexIterator($customRecursiveIterator, '/^.+Regression\.php$/', RecursiveRegexIterator::GET_MATCH);
$errors = [];

$client = new GuzzleHttp\Client([
    'base_uri' => 'https://atatulchenkovbr8906ent.sugardev.io',
    'http_errors' => false,
    'verify' => false,
    'cookies' => true
]);
$logger = new Logger('Regression');

$testsCount = 0;
$failed = 0;
$success = 0;

foreach ($customRegexIterator as $match) {
    $classFile = $match[0];
    require_once $classFile;
    $class = basename($classFile, '.php');
    /**
     * @var \Regression\Scenario $scenario
     */
    $scenario = new $class($client, $logger);
    $testsCount++;
    try {
        $scenario->run();
        $success++;
    } catch (RegressionException $exception) {
        $logger->critical($exception->getMessage());
        $failed++;
    }
}
echo "\nTotal tests: $testsCount. Succeed: $success. Failed: $failed\n";