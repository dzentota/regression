<?php
declare(strict_types=1);

namespace Regression\Runner;

/**
 * Shared resources a scenario reads and writes, detected from its source without loading it
 * (loading a scenario class must not trigger side effects).
 *
 * Write '*' means exclusive: nothing else runs at the same time.
 * Writers of a resource conflict with its readers and writers; readers don't conflict with each other.
 */
final class Locks
{
    public const ALL = '*';

    /** Instance-wide side effects: other scenarios would observe them. regexp => reason */
    public const GLOBAL_PATTERNS = [
        // also catches '/Administration/packages/' . $id . '/install' (concatenated) and uninstall/enable/disable
        '/installMLP|installUploadedPackage|UpgradeWizard_commit|[\'"]\/(?:un)?install[\'"\/?]|\/packages\/[^\'"]*\/(?:un)?install|\/(?:enable|disable)[\'"]/i' => 'installs a package (rebuilds metadata)',
        '/WorkFlow/' => 'workflows fire on other scenarios\' saves',
        '/portalconfigsave|\/config\/|GlobalSearchSettings|module-names-and-icons|RebuildRelationship|apiplatforms|saveApiPlatforms|denormalization\/configuration|Configurator|LicenseSettings/i' => 'changes global configuration',
        '/saveSugarField|savedropdown|saveAndPublishLayout|[\'"=]saveField|SaveRelationship|editLabels|SaveLabel|module=Studio|renameModules/i' => 'changes live Studio metadata',
        '/RepairAndClear|QuickRepair|action=repair|RebuildExtensions/i' => 'rebuilds caches',
    ];

    /** Administration actions known to have no instance-wide effect. */
    private const ADMIN_READ_ONLY = '/^(Diagnostic|DiagnosticDownload|DiagnosticRun|index|checkFTSConnection)$/';

    /** resource => regexp */
    private const RESOURCE_PATTERNS = [
        'packages' => '/uploadMLP|UpgradeWizard|\/Administration\/packages|module=ModuleLoader/',
        'modulebuilder' => '/module=ModuleBuilder/',
    ];

    /** Assigns roles to users (BWC Save2 into the users subpanel, or REST links): changes permissions of the
     * (non-admin) users it logs in as. Admin is never the target. Creating or reading roles alone is harmless. */
    private const ROLE_WRITE_PATTERN = '/[\'"]action[\'"]\s*=>\s*[\'"]Save2[\'"]|link\/aclroles|ACLRoles\/[^\'"]*\/link\/users/';

    /** Changes preferences of the users it logs in as, admin included. */
    private const PREFERENCES_WRITE_PATTERN = '/\/me\/preferences[\'"]\s*,\s*[\'"]PUT/i';

    /**
     * @param string[] $read
     * @param string[] $write
     */
    public function __construct(public readonly array $read, public readonly array $write, public readonly string $reason)
    {
    }

    public static function detect(string $file): self
    {
        $source = (string)file_get_contents($file);
        $write = [];
        $reasons = [];

        $parallel = (bool)preg_match('/#\[\s*(?:\\\\?Regression\\\\Attribute\\\\)?Parallel\b/', $source);
        if (preg_match('/#\[\s*(?:\\\\?Regression\\\\Attribute\\\\)?Exclusive\b(?:\(\s*[\'"]([^\'"]*))?/', $source, $m)) {
            return new self([], [self::ALL], 'marked #[Exclusive]' . (!empty($m[1]) ? ": {$m[1]}" : ''));
        }
        if (!$parallel) {
            foreach (self::GLOBAL_PATTERNS as $regexp => $reason) {
                if (preg_match($regexp, $source)) {
                    return new self([], [self::ALL], "auto: $reason");
                }
            }
            // Unknown Administration actions are assumed to change global settings.
            if (preg_match_all('/module=Administration&action=(\w+)|[\'"]module[\'"]\s*=>\s*[\'"]Administration[\'"][^\]]*?[\'"]action[\'"]\s*=>\s*[\'"](\w+)/s', $source, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $hit) {
                    $action = $hit[1] ?: ($hit[2] ?? '');
                    if (!preg_match(self::ADMIN_READ_ONLY, $action)) {
                        return new self([], [self::ALL], "auto: Administration action '$action'");
                    }
                }
            }
        }

        if (preg_match('/#\[\s*(?:\\\\?Regression\\\\Attribute\\\\)?Uses\s*\(([^)]*)\)/', $source, $m)) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $names);
            array_push($write, ...$names[1]);
            $reasons[] = 'uses ' . implode(', ', $names[1]);
        }
        foreach (self::RESOURCE_PATTERNS as $resource => $regexp) {
            if (preg_match($regexp, $source) && !in_array($resource, $write, true)) {
                $write[] = $resource;
                $reasons[] = "auto: $resource";
            }
        }

        preg_match_all('/loginAs\(\s*[\'"](\w+)[\'"]\s*\)/', $source, $m);
        $users = array_values(array_unique(array_map(fn($u) => 'user:' . strtolower($u), $m[1])));
        // Which of its users is changed can't be told statically: lock all candidates for writing.
        $changed = [];
        if (preg_match(self::PREFERENCES_WRITE_PATTERN, $source)) {
            $changed = $users;
        } elseif (preg_match(self::ROLE_WRITE_PATTERN, $source)) {
            $changed = array_values(array_diff($users, ['user:admin']));
        }
        if ($changed) {
            $write = array_values(array_unique([...$write, ...$changed]));
            $reasons[] = 'changes ' . implode(', ', $changed);
        }
        return new self(array_values(array_diff($users, $write)), $write, implode('; ', $reasons));
    }

    public function isExclusive(): bool
    {
        return in_array(self::ALL, $this->write, true);
    }

    /**
     * @param array<string, int> $readers resource => number of running readers
     * @param array<string, true> $writers resources held for writing
     */
    public function canRun(array $readers, array $writers): bool
    {
        if (isset($writers[self::ALL])) {
            return false;
        }
        if ($this->isExclusive()) {
            return empty($readers) && empty($writers);
        }
        foreach ($this->write as $resource) {
            if (isset($writers[$resource]) || !empty($readers[$resource])) {
                return false;
            }
        }
        foreach ($this->read as $resource) {
            if (isset($writers[$resource])) {
                return false;
            }
        }
        return true;
    }
}
