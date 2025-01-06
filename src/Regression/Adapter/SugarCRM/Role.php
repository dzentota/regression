<?php
declare(strict_types=1);

namespace Regression\Adapter\SugarCRM;

use Regression\Adapter\SugarCRM\ACL\ModulePermissions;

final class Role
{
    private ?string $id = null;
    private array $permissions = [];

    public function __construct(private readonly ?string $name = null, private readonly ?string $description = null)
    {

    }

    public function getId(): ?string
    {
        return $this->id ?? null;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name ?? 'role_' . time();
    }

    public function getDescription(): ?string
    {
        return $this->description ?? '';
    }

    /**
     */
    public function setPermissions(ModulePermissions ...$permissions): void
    {
        $this->permissions = $permissions;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }
}
