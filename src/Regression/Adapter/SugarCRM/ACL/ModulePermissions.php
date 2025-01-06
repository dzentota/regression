<?php
declare(strict_types=1);

namespace Regression\Adapter\SugarCRM\ACL;

use Regression\Adapter\Sugarcrm\Module;

final class ModulePermissions
{
    private readonly Module $module;
    private readonly array $permissions;

    public function __construct(Module $module, Action ...$action)
    {
        $this->module = $module;
        $this->permissions = $action;
    }

    public function getModule(): Module
    {
        return $this->module;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }
}
