<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Users\Events\AccessCapabilitiesCollecting;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class ConfiguredAccessMetadataProvider implements AccessMetadataProvider
{
    public function __construct(
        private MatomoTranslator $translator,
        private Dispatcher $events,
    ) {}

    public function roles(string $language): array
    {
        $write = $this->translator->translate('UsersManager_PrivWrite', $language);

        return [
            $this->role('view', 'UsersManager_PrivView', 'UsersManager_PrivViewDescription', $language),
            $this->role('write', 'UsersManager_PrivWrite', 'UsersManager_PrivWriteDescription', $language),
            [
                'id' => 'admin',
                'name' => $this->translator->translate('UsersManager_PrivAdmin', $language),
                'description' => $this->translator->translate(
                    'UsersManager_PrivAdminDescription',
                    $language,
                    [$write],
                ),
                'helpUrl' => '',
            ],
        ];
    }

    public function capabilities(): array
    {
        $event = new AccessCapabilitiesCollecting;
        $this->events->dispatch($event);

        return $event->capabilities;
    }

    /** @return array{id: string, name: string, description: string, helpUrl: string} */
    private function role(string $id, string $name, string $description, string $language): array
    {
        return [
            'id' => $id,
            'name' => $this->translator->translate($name, $language),
            'description' => $this->translator->translate($description, $language),
            'helpUrl' => '',
        ];
    }
}
