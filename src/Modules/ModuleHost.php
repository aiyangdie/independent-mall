<?php

declare(strict_types=1);

namespace IndependentMall\Modules;

use DomainException;
use IndependentMall\Config\ShopSettings;
use IndependentMall\Security\AuditLogger;
use PDO;

final class ModuleHost
{
    /** @var list<Module> */
    private array $modules = [];

    /** @var list<string> */
    private array $faults = [];

    public function __construct(
        private PDO $pdo,
        private string $root,
        private LicenseGate $license,
        private ?AuditLogger $audit = null,
    ) {
        $this->discover();
    }

    /**
     * @return list<Module>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * @return list<string>
     */
    public function faults(): array
    {
        return $this->faults;
    }

    public function isEnabled(string $id): bool
    {
        try {
            return (new ShopSettings($this->pdo))->get('module.' . $id . '.enabled', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public function storefrontNotice(): string
    {
        $parts = [];
        foreach ($this->modules as $module) {
            if (!$this->isEnabled($module->id()) || !$this->license->allows($module->entitlements())) {
                continue;
            }
            try {
                $text = trim($module->storefrontNotice($this->pdo));
                if ($text !== '') {
                    $parts[] = $text;
                }
            } catch (\Throwable $e) {
                $this->faults[] = $module->id() . ': ' . $e->getMessage();
            }
        }
        return implode(' ', $parts);
    }

    public function migrate(string $id, ?int $actorId): void
    {
        $module = $this->must($id);
        $module->migrate($this->pdo);
        $this->audit?->record($actorId, 'module.migrate', 'module', $id);
    }

    public function enable(string $id, ?int $actorId): void
    {
        $module = $this->must($id);
        if (!$this->license->allows($module->entitlements())) {
            throw new DomainException('No locally verified entitlement for this module. The community shop stays available.');
        }
        if (!$module->isMigrated($this->pdo)) {
            throw new DomainException('Run module migration before enabling.');
        }
        $module->enable($this->pdo);
        $this->flag($id, '1');
        $this->audit?->record($actorId, 'module.enable', 'module', $id);
    }

    public function disable(string $id, ?int $actorId): void
    {
        $module = $this->must($id);
        $module->disable($this->pdo);
        $this->flag($id, '0');
        $this->audit?->record($actorId, 'module.disable', 'module', $id);
    }

    public function uninstall(string $id, ?int $actorId): void
    {
        $module = $this->must($id);
        $this->flag($id, '0');
        $module->uninstall($this->pdo);
        $this->audit?->record($actorId, 'module.uninstall', 'module', $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Module $module): array
    {
        return [
            'id' => $module->id(),
            'label' => $module->label(),
            'entitlements' => $module->entitlements(),
            'migrated' => $module->isMigrated($this->pdo),
            'enabled' => $this->isEnabled($module->id()),
            'licensed' => $this->license->allows($module->entitlements()),
        ];
    }

    private function discover(): void
    {
        $pattern = $this->root . '/modules/*/module.php';
        foreach (glob($pattern) ?: [] as $file) {
            $name = basename(dirname($file));
            if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
                continue;
            }
            try {
                $loaded = require $file;
                if (!$loaded instanceof Module) {
                    $this->faults[] = $file . ' did not return a Module';
                    continue;
                }
                $this->modules[] = $loaded;
            } catch (\Throwable $e) {
                $this->faults[] = basename(dirname($file)) . ': ' . $e->getMessage();
            }
        }
    }

    private function must(string $id): Module
    {
        foreach ($this->modules as $module) {
            if ($module->id() === $id) {
                return $module;
            }
        }
        throw new DomainException('Unknown module.');
    }

    private function flag(string $id, string $value): void
    {
        (new ShopSettings($this->pdo, $this->audit))->put('module.' . $id . '.enabled', $value === '1' ? '1' : '0', null);
    }
}
