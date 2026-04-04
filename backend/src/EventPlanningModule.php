<?php

namespace NewSolari\EventPlanning;

use NewSolari\Core\Module\Contracts\ModuleInterface;

class EventPlanningModule implements ModuleInterface
{
    public function getId(): string { return 'event-plans'; }
    public function getName(): string { return 'Event Planning'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'meta-app'; }

    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}

    public function getDependencies(): array { return ['core' => '>=1.0.0']; }
    public function getOptionalDependencies(): array { return []; }
    public function getServiceContract(): ?string { return null; }

    public function getFrontendManifest(): ?array
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../module.json'), true);
        return $manifest['frontend'] ?? null;
    }
}
