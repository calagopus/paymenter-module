<?php

namespace Paymenter\Extensions\Servers\Calagopus;

use App\Classes\Extension\Server;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Calagopus\Admin\Actions\SyncOAuthLinksAction;
use Paymenter\Extensions\Servers\Calagopus\Support\CalagopusAPI;
use Paymenter\Extensions\Servers\Calagopus\Support\OAuthSync;

class Calagopus extends Server
{
    private ?CalagopusAPI $api = null;

    public function boot()
    {
        View::addNamespace('calagopus', __DIR__ . '/resources/views');
    }

    private function api(): CalagopusAPI
    {
        return $this->api ??= new CalagopusAPI($this->config('host'), $this->config('api_key'));
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Panel URL',
                'type' => 'text',
                'description' => 'Full URL of your Calagopus panel (e.g. https://panel.example.com)',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'description' => 'Admin API key for Calagopus',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'default_language',
                'label' => 'Default User Language',
                'type' => 'text',
                'description' => 'Default language for created users (e.g. "en").',
                'required' => false,
                'default' => 'en',
                'encrypted' => false,
                'validation' => 'alpha|size:2',
            ],
            [
                'name' => 'oauth_provider_uuid',
                'label' => 'OAuth Provider UUID',
                'type' => 'text',
                'description' => 'UUID of the Paymenter OAuth provider configured in Calagopus. Used to link users.',
                'required' => false,
                'encrypted' => false,
                'action' => SyncOAuthLinksAction::class,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->api()->getLocations(perPage: 1);
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function getProductConfig($values = []): array
    {
        $nestList = [];
        try {
            foreach ($this->api()->getNests() as $nest) {
                $nestList[$nest['uuid']] = $nest['name'];
            }
        } catch (Exception $e) {
            // Ignore
        }

        $eggList = [];
        if (isset($values['nest_uuid']) && $values['nest_uuid'] !== '') {
            try {
                foreach ($this->api()->getEggs($values['nest_uuid']) as $egg) {
                    $eggList[$egg['uuid']] = $egg['name'];
                }
            } catch (Exception $e) {
                // Ignore
            }
        }

        $locationList = [];
        try {
            foreach ($this->api()->getLocations() as $loc) {
                $locationList[$loc['uuid']] = $loc['name'];
            }
        } catch (Exception $e) {
            // Ignore
        }

        $nodeList = ['' => '-- Auto (use locations) --'];
        try {
            foreach ($this->api()->getNodes() as $node) {
                $nodeList[$node['uuid']] = $node['name'] . ' (' . ($node['location']['name'] ?? 'N/A') . ')';
            }
        } catch (Exception $e) {
            // Ignore
        }

        return [
            [
                'name' => 'nest_uuid',
                'label' => 'Nest',
                'type' => 'select',
                'options' => $nestList,
                'required' => true,
                'live' => true,
            ],
            [
                'name' => 'egg_uuid',
                'label' => 'Egg',
                'type' => 'select',
                'options' => $eggList,
                'required' => true,
            ],
            [
                'name' => 'node_uuid',
                'label' => 'Node',
                'type' => 'select',
                'options' => $nodeList,
                'description' => 'Select a specific node, or leave as Auto to use location-based deploy.',
            ],
            [
                'name' => 'location_uuids',
                'label' => 'Location(s) (deploy mode)',
                'type' => 'select',
                'options' => $locationList,
                'multiple' => true,
                'database_type' => 'array',
                'description' => 'Used when no specific node is selected.',
                'required' => false,
            ],
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 0,
                'default' => 1024,
            ],
            [
                'name' => 'swap',
                'label' => 'Swap',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => -1,
                'default' => 0,
                'description' => 'Set to -1 for unlimited, 0 to disable.',
            ],
            [
                'name' => 'disk',
                'label' => 'Disk',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 0,
                'default' => 10240,
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Limit',
                'type' => 'number',
                'suffix' => '%',
                'required' => true,
                'min_value' => 0,
                'default' => 100,
                'description' => '100 = 1 thread. Set to 0 for unlimited.',
            ],
            [
                'name' => 'memory_overhead',
                'label' => 'Memory Overhead',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => false,
                'min_value' => 0,
                'default' => 0,
                'description' => 'Hidden memory added to the container.',
            ],
            [
                'name' => 'io_weight',
                'label' => 'IO Weight',
                'type' => 'number',
                'required' => false,
                'min_value' => 10,
                'max_value' => 1000,
                'description' => 'Leave empty for default.',
            ],
            [
                'name' => 'allocations_limit',
                'label' => 'Allocations',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'default' => 1,
            ],
            [
                'name' => 'database_limit',
                'label' => 'Databases',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'default' => 0,
            ],
            [
                'name' => 'backup_limit',
                'label' => 'Backups',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'default' => 0,
            ],
            [
                'name' => 'schedule_limit',
                'label' => 'Schedules',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'default' => 0,
            ],
            [
                'name' => 'custom_feature_limits',
                'label' => 'Custom Feature Limits',
                'type' => 'text',
                'description' => 'Extension-added limits. Format: key:value,key:value (e.g. plugins:5,worlds:3)',
                'required' => false,
            ],
            [
                'name' => 'docker_image',
                'label' => 'Docker Image',
                'type' => 'text',
                'description' => 'Override the egg default. Leave blank for egg default.',
                'required' => false,
            ],
            [
                'name' => 'startup_command',
                'label' => 'Startup Command',
                'type' => 'text',
                'description' => 'Override the egg default startup. Leave blank for egg default.',
                'required' => false,
            ],
            [
                'name' => 'server_name_prefix',
                'label' => 'Server Name Prefix',
                'type' => 'text',
                'description' => 'E.g. "MC-" → "MC-12345". Blank defaults to "Server-".',
                'required' => false,
            ],
            [
                'name' => 'skip_installer',
                'label' => 'Skip Egg Install Script',
                'type' => 'checkbox',
                'description' => 'Skip the egg installation script if one is attached.',
            ],
            [
                'name' => 'start_on_completion',
                'label' => 'Start on Completion',
                'type' => 'checkbox',
                'description' => 'Start the server automatically after installation.',
            ],
            [
                'name' => 'hugepages_passthrough',
                'label' => 'Hugepages Passthrough',
                'type' => 'checkbox',
                'description' => 'Mount /dev/hugepages into the container.',
            ],
            [
                'name' => 'kvm_passthrough',
                'label' => 'KVM Passthrough',
                'type' => 'checkbox',
                'description' => 'Allow access to /dev/kvm inside the container.',
            ],
            [
                'name' => 'pinned_cpus',
                'label' => 'Pinned CPUs',
                'type' => 'text',
                'description' => 'Comma-separated CPU core IDs. E.g. "0,1,2". Leave blank for no pinning.',
                'required' => false,
            ],
            [
                'name' => 'backup_configuration_uuid',
                'label' => 'Backup Configuration UUID',
                'type' => 'text',
                'description' => 'Optional backup configuration to assign.',
                'required' => false,
            ],
        ];
    }

    private function parseCustomFeatureLimits(string $raw): array
    {
        $limits = [];
        $raw = trim($raw);
        if (empty($raw)) {
            return $limits;
        }

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if (str_contains($pair, ':')) {
                [$key, $value] = explode(':', $pair, 2);
                $key = trim($key);
                $value = trim($value);
                if ($key !== '') {
                    if (is_numeric($value)) {
                        $limits[$key] = (int) $value;
                    } elseif (strtolower($value) === 'true' || strtolower($value) === 'false') {
                        $limits[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $limits[$key] = $value;
                    }
                }
            }
        }

        return $limits;
    }

    private function parsePinnedCpus(string $raw): array
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return [];
        }

        return array_map('intval', array_filter(array_map('trim', explode(',', $raw)), 'is_numeric'));
    }

    /**
     * Read a setting/property using exact key match first and case-insensitive fallback second.
     * This lets Paymenter config options override egg variables as long as the key matches the egg env variable.
     */
    private function getSettingValue(array $settings, string $key, mixed &$value): bool
    {
        if (array_key_exists($key, $settings)) {
            $value = $settings[$key];

            return true;
        }

        foreach ($settings as $settingKey => $settingValue) {
            if (is_string($settingKey) && strcasecmp($settingKey, $key) === 0) {
                $value = $settingValue;

                return true;
            }
        }

        return false;
    }

    private function stringifySettingValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', array_map(fn ($item) => (string) $item, $value));
        }

        return (string) ($value ?? '');
    }

    private function boolSetting(array $settings, string $key, bool $default = false): bool
    {
        $value = null;
        if (!$this->getSettingValue($settings, $key, $value)) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function buildEggVariablesPayload(array $eggVariables, array $settings): array
    {
        $variables = [];

        foreach ($eggVariables as $var) {
            $envKey = $var['env_variable'] ?? null;
            if (!$envKey) {
                continue;
            }

            $value = null;
            if (!$this->getSettingValue($settings, $envKey, $value)) {
                $value = $var['default_value'] ?? '';
            }

            $variables[] = [
                'env_variable' => $envKey,
                'value' => $this->stringifySettingValue($value),
            ];
        }

        return $variables;
    }

    private function sanitizeServerName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = preg_replace('/[^\pL\pN _\.\-]/u', '', $name);
        $name = trim($name);

        return Str::limit($name, 48, '');
    }

    private function buildServerName(array $settings, Service $service): string
    {
        $name = null;
        $hasName = $this->getSettingValue($settings, 'server_name', $name)
            || $this->getSettingValue($settings, 'custom_server_name', $name)
            || $this->getSettingValue($settings, 'SERVER_NAME', $name);

        if ($hasName) {
            $cleanName = $this->sanitizeServerName($this->stringifySettingValue($name));
            if ($cleanName !== '') {
                return $cleanName;
            }
        }

        $prefix = trim((string) ($settings['server_name_prefix'] ?? ''));

        return ($prefix ?: 'Server-') . $service->id;
    }

    /**
     * Find a server by the service's external ID.
     */
    private function findServer(int $serviceId, bool $failIfNotFound = true): ?array
    {
        $server = $this->api()->getServerByExternalId((string) $serviceId);

        if (!$server && $failIfNotFound) {
            throw new Exception('Server not found on the panel.');
        }

        return $server;
    }

    /**
     * Generate a panel username for a Paymenter user, capped at the panel's 15 character limit.
     */
    private function generateUsername($orderUser): string
    {
        $baseName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(Str::transliterate($orderUser->name ?? 'user')));
        if (strlen($baseName) < 3) {
            $baseName = 'user';
        }

        $idStr = (string) $orderUser->id;
        $maxNameLen = max(0, 14 - strlen($idStr));

        return substr($baseName, 0, $maxNameLen) . '_' . $idStr;
    }

    /**
     * Create the OAuth link for a panel user, if a provider is configured.
     * Failures are swallowed — a missing link is not worth failing provisioning over.
     */
    private function linkOAuthProvider(string $panelUserUuid, $orderUser): void
    {
        $oauthProviderUuid = $this->config('oauth_provider_uuid');

        if (!$oauthProviderUuid) {
            return;
        }

        try {
            $this->api()->createOAuthLink($panelUserUuid, $oauthProviderUuid, (string) $orderUser->id);
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Find or create a Calagopus panel user for the given service user.
     * On 409 (email/username already exists) only links the existing user by email, and only if the customer's email is verified.
     */
    private function findOrCreateUser($orderUser): array
    {
        // Try lookup by oauth identifier if configured — already linked, nothing more to do
        $oauthProviderUuid = $this->config('oauth_provider_uuid');

        if ($oauthProviderUuid) {
            $linked = $this->api()->getOAuthLinkedUser($oauthProviderUuid, (string) $orderUser->id);
            if ($linked) {
                return $linked;
            }
        }

        $panelUser = $this->api()->findOrCreateUser(
            externalId: (string) $orderUser->id,
            email: $orderUser->email,
            firstName: $orderUser->first_name ?? $orderUser->name ?? 'User',
            lastName: $orderUser->last_name ?? '',
            username: $this->generateUsername($orderUser),
            language: $this->config('default_language') ?: 'en',
            emailVerified: $orderUser->hasVerifiedEmail(),
        );

        $this->linkOAuthProvider($panelUser['uuid'], $orderUser);

        return $panelUser;
    }

    /**
     * Link an existing Paymenter user to the configured OAuth provider on the panel.
     * Unlike findOrCreateUser this never provisions a panel account.
     *
     * @return string One of the OAuthSync::RESULT_* constants.
     */
    public function syncOAuthLink($orderUser): string
    {
        $oauthProviderUuid = $this->config('oauth_provider_uuid');

        if (!$oauthProviderUuid) {
            throw new Exception('No OAuth Provider UUID is configured for this server.');
        }

        if ($this->api()->getOAuthLinkedUser($oauthProviderUuid, (string) $orderUser->id)) {
            return OAuthSync::RESULT_ALREADY_LINKED;
        }

        $panelUser = $this->api()->getUserByExternalId((string) $orderUser->id);

        if (!$panelUser) {
            return OAuthSync::RESULT_NO_PANEL_USER;
        }

        if (strcasecmp($panelUser['email'] ?? '', $orderUser->email) !== 0) {
            return OAuthSync::RESULT_EMAIL_MISMATCH;
        }

        if (!$orderUser->hasVerifiedEmail()) {
            return OAuthSync::RESULT_EMAIL_UNVERIFIED;
        }

        $this->api()->createOAuthLink($panelUser['uuid'], $oauthProviderUuid, (string) $orderUser->id);

        return OAuthSync::RESULT_LINKED;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->findServer($service->id, failIfNotFound: false)) {
            throw new Exception('Server already exists on the panel.');
        }

        $settings = array_merge($settings, $properties);

        $panelUser = $this->findOrCreateUser($service->user);

        $nestUuid = $settings['nest_uuid'];
        $eggUuid = $settings['egg_uuid'];

        $egg = $this->api()->getEgg($nestUuid, $eggUuid);
        $eggVariables = $this->api()->getEggVariables($nestUuid, $eggUuid);

        $dockerImage = !empty($settings['docker_image'])
            ? $settings['docker_image']
            : (array_values($egg['docker_images'])[0] ?? '');
        $startup = !empty($settings['startup_command'])
            ? $settings['startup_command']
            : (isset($egg['startup']) ? $egg['startup'] : (isset($egg['startup_commands']['Default'])
                ? $egg['startup_commands']['Default']
                : (array_values($egg['startup_commands'])[0] ?? '')));

        $serverName = $this->buildServerName($settings, $service);

        $featureLimits = [
            'allocations' => (int) ($settings['allocations_limit'] ?? 1),
            'databases' => (int) ($settings['database_limit'] ?? 0),
            'backups' => (int) ($settings['backup_limit'] ?? 0),
            'schedules' => (int) ($settings['schedule_limit'] ?? 0),
        ];
        $customLimits = $this->parseCustomFeatureLimits($settings['custom_feature_limits'] ?? '');
        $featureLimits = array_merge($featureLimits, $customLimits);

        $variables = $this->buildEggVariablesPayload($eggVariables, $settings);

        $ioWeight = isset($settings['io_weight']) && $settings['io_weight'] !== '' ? (int) $settings['io_weight'] : null;

        $serverPayload = [
            'owner_uuid' => $panelUser['uuid'],
            'egg_uuid' => $eggUuid,
            'start_on_completion' => $this->boolSetting($settings, 'start_on_completion'),
            'skip_installer' => $this->boolSetting($settings, 'skip_installer'),
            'external_id' => (string) $service->id,
            'name' => $serverName,
            'limits' => [
                'cpu' => (int) ($settings['cpu'] ?? 100),
                'memory' => (int) ($settings['memory'] ?? 1024),
                'memory_overhead' => (int) ($settings['memory_overhead'] ?? 0),
                'swap' => (int) ($settings['swap'] ?? 0),
                'disk' => (int) ($settings['disk'] ?? 10240),
            ],
            'pinned_cpus' => $this->parsePinnedCpus($settings['pinned_cpus'] ?? ''),
            'startup' => $startup,
            'image' => $dockerImage,
            'hugepages_passthrough_enabled' => $this->boolSetting($settings, 'hugepages_passthrough'),
            'kvm_passthrough_enabled' => $this->boolSetting($settings, 'kvm_passthrough'),
            'feature_limits' => $featureLimits,
            'variables' => $variables,
        ];

        if ($ioWeight !== null) {
            $serverPayload['limits']['io_weight'] = $ioWeight;
        }

        $backupConfigUuid = trim($settings['backup_configuration_uuid'] ?? '');
        if ($backupConfigUuid) {
            $serverPayload['backup_configuration_uuid'] = $backupConfigUuid;
        }

        $nodeUuid = $settings['node_uuid'] ?? '';

        if (!empty($nodeUuid)) {
            $allocations = $this->api()->getAvailableAllocations($nodeUuid);
            if (empty($allocations)) {
                throw new Exception('No available allocations on the selected node.');
            }

            $serverPayload['node_uuid'] = $nodeUuid;
            $serverPayload['allocation_uuid'] = $allocations[0]['uuid'];
            $serverPayload['allocation_uuids'] = [];

            $server = $this->api()->createServer($serverPayload);
        } else {
            // Auto-deploy with locations
            $locationUuids = $settings['location_uuids'] ?? [];
            if (is_string($locationUuids)) {
                $locationUuids = array_filter(array_map('trim', explode(',', $locationUuids)));
            }
            if (empty($locationUuids)) {
                throw new Exception('No node or location UUIDs configured for this product.');
            }

            $serverPayload['deployment'] = [
                'location_uuids' => array_values($locationUuids),
                'allow_overallocation' => false,
            ];

            $server = $this->api()->deployServer($serverPayload);
        }

        return [
            'server_uuid' => $server['uuid'],
            'link' => $this->api()->getServerUrl($server['uuid']),
        ];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->api()->suspendServer($this->findServer($service->id)['uuid']);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->api()->unsuspendServer($this->findServer($service->id)['uuid']);

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->api()->deleteServer($this->findServer($service->id)['uuid']);

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $server = $this->findServer($service->id);
        $settings = array_merge($settings, $properties);

        $featureLimits = [
            'allocations' => (int) ($settings['allocations_limit'] ?? 1),
            'databases' => (int) ($settings['database_limit'] ?? 0),
            'backups' => (int) ($settings['backup_limit'] ?? 0),
            'schedules' => (int) ($settings['schedule_limit'] ?? 0),
        ];
        $customLimits = $this->parseCustomFeatureLimits($settings['custom_feature_limits'] ?? '');
        $featureLimits = array_merge($featureLimits, $customLimits);

        $ioWeight = isset($settings['io_weight']) && $settings['io_weight'] !== '' ? (int) $settings['io_weight'] : null;

        $updateData = [
            'limits' => [
                'cpu' => (int) ($settings['cpu'] ?? 100),
                'memory' => (int) ($settings['memory'] ?? 1024),
                'memory_overhead' => (int) ($settings['memory_overhead'] ?? 0),
                'swap' => (int) ($settings['swap'] ?? 0),
                'disk' => (int) ($settings['disk'] ?? 10240),
            ],
            'feature_limits' => $featureLimits,
            'hugepages_passthrough_enabled' => $this->boolSetting($settings, 'hugepages_passthrough'),
            'kvm_passthrough_enabled' => $this->boolSetting($settings, 'kvm_passthrough'),
            'pinned_cpus' => $this->parsePinnedCpus($settings['pinned_cpus'] ?? ''),
        ];

        if ($ioWeight !== null) {
            $updateData['limits']['io_weight'] = $ioWeight;
        }

        $dockerImage = trim($settings['docker_image'] ?? '');
        if ($dockerImage) {
            $updateData['image'] = $dockerImage;
        }

        $this->api()->updateServer($server['uuid'], $updateData);

        return true;
    }

    public function getActions(Service $service)
    {
        $server = $this->findServer($service->id, failIfNotFound: false);

        if (!$server) {
            return [];
        }

        return [
            [
                'type' => 'button',
                'label' => 'Go to Server',
                'url' => $this->api()->getServerUrl($server['uuid']),
            ],
        ];
    }
}
