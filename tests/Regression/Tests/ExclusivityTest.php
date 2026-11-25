<?php
declare(strict_types=1);

namespace Regression\Tests;

use PHPUnit\Framework\TestCase;
use Regression\Runner\Exclusivity;

final class ExclusivityTest extends TestCase
{
    private function reasonFor(string $source): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'excl') . 'Regression.php';
        file_put_contents($file, "<?php\n" . $source);
        try {
            return Exclusivity::reason($file);
        } finally {
            unlink($file);
        }
    }

    public function testPlainScenarioIsParallel(): void
    {
        $this->assertNull($this->reasonFor('class A { function run() { $this->apiCall("/Accounts", "POST"); } }'));
    }

    public function testExplicitAttribute(): void
    {
        $this->assertSame(
            'marked #[Exclusive]: installs MLP',
            $this->reasonFor("#[Exclusive('installs MLP')]\nclass A {}")
        );
        $this->assertSame('marked #[Exclusive]', $this->reasonFor("#[\\Regression\\Attribute\\Exclusive]\nclass A {}"));
    }

    public function testAutoDetection(): void
    {
        $this->assertStringStartsWith('auto:', (string)$this->reasonFor('class A { function run() { $this->installMLP($x); } }'));
        $this->assertStringStartsWith('auto:', (string)$this->reasonFor('class A { function run() { $this->get("index.php?module=Administration&action=x"); } }'));
    }

    public function testParallelAttributeOverridesAutoDetection(): void
    {
        $this->assertNull($this->reasonFor("#[Parallel]\nclass A { function run() { \$this->get('index.php?module=Administration'); } }"));
    }
}
