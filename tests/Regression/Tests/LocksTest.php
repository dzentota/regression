<?php
declare(strict_types=1);

namespace Regression\Tests;

use PHPUnit\Framework\TestCase;
use Regression\Runner\Locks;

final class LocksTest extends TestCase
{
    private function locks(string $source): Locks
    {
        $file = tempnam(sys_get_temp_dir(), 'locks') . 'Regression.php';
        file_put_contents($file, "<?php\n" . $source);
        try {
            return Locks::detect($file);
        } finally {
            unlink($file);
        }
    }

    public function testLoginsAreReadLocks(): void
    {
        $l = $this->locks('class A { function run() { $this->loginAs("admin"); $this->loginAs("sarah"); } }');
        $this->assertSame(['user:admin', 'user:sarah'], $l->read);
        $this->assertSame([], $l->write);
    }

    public function testRoleChangesWriteNonAdminUsers(): void
    {
        $l = $this->locks('class A { function run() { $this->loginAs("admin")->submitForm("index.php", ["module" => "ACLRoles", "action" => "Save2"]); $this->loginAs("sally"); } }');
        $this->assertSame(['user:sally'], $l->write);
        $this->assertSame(['user:admin'], $l->read);
    }

    public function testCreatingOrReadingRolesIsNotAWrite(): void
    {
        $l = $this->locks('class A { function run() { $this->loginAs("admin")->createRole($r); $this->loginAs("will")->apiCall("/ACLRoles"); } }');
        $this->assertSame([], $l->write);
    }

    public function testPreferencesWriteAdminToo(): void
    {
        $l = $this->locks('class A { function run() { $this->loginAs("admin")->apiCall("/me/preferences", "PUT", []); } }');
        $this->assertSame(['user:admin'], $l->write);
    }

    public function testGlobalPatternsAndAttributes(): void
    {
        $this->assertTrue($this->locks('class A { function run() { $this->installMLP($m); } }')->isExclusive());
        $this->assertTrue($this->locks('class A { function run() { $this->get("index.php?module=Administration&action=Upgrade"); } }')->isExclusive());
        $this->assertFalse($this->locks('class A { function run() { $this->get("index.php?module=Administration&action=Diagnostic"); } }')->isExclusive());
        $this->assertTrue($this->locks("#[Exclusive('x')]\nclass A {}")->isExclusive());
        $this->assertFalse($this->locks("#[Parallel]\nclass A { function run() { \$this->get('x/WorkFlow'); } }")->isExclusive());
        $this->assertSame(['packages', 'foo'], $this->locks("#[Uses('packages', 'foo')]\nclass A {}")->write);
    }

    public function testCompatibility(): void
    {
        $sarahWriter = new Locks([], ['user:sarah'], '');
        $sarahReader = new Locks(['user:sarah'], [], '');
        $exclusive = new Locks([], [Locks::ALL], '');

        $this->assertTrue($sarahReader->canRun(['user:sarah' => 2], []));
        $this->assertFalse($sarahWriter->canRun(['user:sarah' => 1], []));
        $this->assertFalse($sarahReader->canRun([], ['user:sarah' => true]));
        $this->assertTrue($sarahWriter->canRun(['user:max' => 1], ['packages' => true]));
        $this->assertFalse($exclusive->canRun(['user:max' => 1], []));
        $this->assertTrue($exclusive->canRun([], []));
        $this->assertFalse($sarahReader->canRun([], [Locks::ALL => true]));
    }
}
