<?php
declare(strict_types=1);

namespace Regression\Tests;

use PHPUnit\Framework\TestCase;
use Regression\Config;
use Regression\Scenario;

final class LifecycleTest extends TestCase
{
    private function scenario(callable $run, array $config = []): LifecycleScenario
    {
        return new LifecycleScenario(Config::create(['baseUri' => 'https://example.com'] + $config), $run);
    }

    public function testOrderSetUpRunDeferredLifoTearDown(): void
    {
        $s = $this->scenario(function (LifecycleScenario $s) {
            $s->log[] = 'run';
            $s->defer(function () use ($s) { $s->log[] = 'cleanup1'; });
            $s->defer(function () use ($s) { $s->log[] = 'cleanup2'; });
        });
        $s->execute();
        $this->assertSame(['setUp', 'run', 'cleanup2', 'cleanup1', 'tearDown'], $s->log);
    }

    public function testCleanupRunsWhenRunThrowsAndOriginalExceptionIsKept(): void
    {
        $s = $this->scenario(function (LifecycleScenario $s) {
            $s->defer(function () use ($s) { $s->log[] = 'cleanup'; });
            throw new \LogicException('regression');
        });
        try {
            $s->execute();
            $this->fail('Exception expected');
        } catch (\LogicException $e) {
            $this->assertSame('regression', $e->getMessage());
        }
        $this->assertSame(['setUp', 'cleanup', 'tearDown'], $s->log);
    }

    public function testCleanupErrorsAreCollectedAndDoNotStopOtherCleanups(): void
    {
        $s = $this->scenario(function (LifecycleScenario $s) {
            $s->defer(function () use ($s) { $s->log[] = 'cleanup1'; });
            $s->defer(function () { throw new \RuntimeException('boom'); }, 'broken');
        });
        $s->execute();
        $this->assertSame(['setUp', 'cleanup1', 'tearDown'], $s->log);
        $this->assertSame(['broken: boom'], $s->getCleanupErrors());
    }

    public function testCleanupDoesNotChangeOutcome(): void
    {
        $s = $this->scenario(function (LifecycleScenario $s) {
            $s->setOutcome('passed', 'ok');
            $s->defer(function () use ($s) { $s->setOutcome('has_issue', 'from cleanup'); });
        });
        $s->execute();
        $this->assertSame('passed', $s->getStatus());
        $this->assertSame('ok', $s->getConclusion());
    }

    public function testKeepDataSkipsCleanupAndTearDown(): void
    {
        $s = $this->scenario(function (LifecycleScenario $s) {
            $s->defer(function () use ($s) { $s->log[] = 'cleanup'; });
        }, ['keepData' => true]);
        $s->execute();
        $this->assertSame(['setUp'], $s->log);
    }

    public function testUniqueNameContainsRunId(): void
    {
        $s = $this->scenario(fn() => null, ['runId' => 'rgabc']);
        $a = $s->uniqueName('acc');
        $b = $s->uniqueName('acc');
        $this->assertStringStartsWith('acc_rgabc_', $a);
        $this->assertNotSame($a, $b);
    }
}

class LifecycleScenario extends Scenario
{
    public array $log = [];
    private $body;

    public function __construct(Config $config, callable $body)
    {
        parent::__construct($config);
        $this->body = $body;
    }

    public function getDescription(): string
    {
        return 'lifecycle';
    }

    protected function setUp(): void
    {
        $this->log[] = 'setUp';
    }

    protected function tearDown(): void
    {
        $this->log[] = 'tearDown';
    }

    public function run(): void
    {
        ($this->body)($this);
    }

    public function setOutcome(string $status, string $conclusion): void
    {
        $this->status = $status;
        $this->conclusion = $conclusion;
    }
}
