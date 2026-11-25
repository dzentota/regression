<?php
declare(strict_types=1);

namespace Regression\Runner;

use Regression\Attribute\Exclusive;
use Regression\Attribute\Parallel;

/**
 * Decides whether a scenario file must run alone. Explicit attributes win; otherwise the source is
 * scanned for operations known to change instance-wide state.
 */
final class Exclusivity
{
    /** @var array<string, string> regexp => reason */
    public const PATTERNS = [
        '/module=Administration\b|[\'"]\/Administration\b/' => 'changes admin settings',
        '/installMLP|uploadMLP|installUploadedPackage|UpgradeWizard|module=ModuleBuilder|module=ModuleLoader/' => 'installs packages / changes customizations',
        '/module=Studio|action=SaveLabel|SaveDropDown|SaveDropdown|renameModules|wizard=/i' => 'changes Studio metadata',
        '/WorkFlow|PdfManager|module=Configurator|\/Configurator|ACLRoles|createRole\(/' => 'changes global configuration',
        '/RepairAndClear|QuickRepair|action=repair/i' => 'rebuilds caches',
    ];

    /**
     * @return string|null reason why the scenario is exclusive, null if it can run in parallel
     */
    public static function reason(string $file): ?string
    {
        $source = (string)file_get_contents($file);
        if (preg_match('/#\[\s*(?:\\\\?Regression\\\\Attribute\\\\)?Parallel\b/', $source)) {
            return null;
        }
        if (preg_match('/#\[\s*(?:\\\\?Regression\\\\Attribute\\\\)?Exclusive\b(?:\(\s*[\'"]([^\'"]*))?/', $source, $m)) {
            return 'marked #[Exclusive]' . (!empty($m[1]) ? ": {$m[1]}" : '');
        }
        foreach (self::PATTERNS as $regexp => $reason) {
            if (preg_match($regexp, $source)) {
                return "auto: $reason";
            }
        }
        return null;
    }

    // Reflection is deliberately avoided: loading a scenario class must not trigger its side effects.
    private function __construct()
    {
    }
}
