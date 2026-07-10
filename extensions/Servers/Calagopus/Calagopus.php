<?php

namespace Paymenter\Extensions\Servers\Calagopus;

use App\Classes\Extension\Server;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Calagopus extends Server
{
	/**
	 * Make a request to the Calagopus API.
	 */
	private function request(string $url, string $method = 'get', array $data = []): array
	{
		$reqUrl = rtrim($this->config('host'), '/') . $url;
		$response = Http::withHeaders([
			'Authorization' => 'Bearer ' . $this->config('api_key'),
			'Accept' => 'application/json',
		])->$method($reqUrl, $data);

		if (!$response->successful()) {
			$body = $response->json();
			$errors = $body['errors'] ?? ['Unknown API error'];
			throw new Exception('Calagopus API Error (HTTP ' . $response->status() . '): ' . implode(', ', $errors));
		}

		return $response->json() ?? [];
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
			],
		];
	}

	public function testConfig(): bool|string
	{
		try {
			$this->request('/api/admin/locations', 'get', ['page' => 1, 'per_page' => 1]);
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return true;
	}

	public function getProductConfig($values = []): array
	{
		$nestList = [];
		try {
			$nestsData = $this->request('/api/admin/nests', 'get', ['page' => 1, 'per_page' => 100]);
			foreach (($nestsData['nests']['data'] ?? []) as $nest) {
				$nestList[$nest['uuid']] = $nest['name'];
			}
		} catch (Exception $e) {
			// Ignore — will show empty dropdown
		}

		$eggList = [];
		if (isset($values['nest_uuid']) && $values['nest_uuid'] !== '') {
			try {
				$eggsData = $this->request('/api/admin/nests/' . $values['nest_uuid'] . '/eggs', 'get', ['page' => 1, 'per_page' => 100]);
				foreach (($eggsData['eggs']['data'] ?? []) as $egg) {
					$eggList[$egg['uuid']] = $egg['name'];
				}
			} catch (Exception $e) {
				// Ignore
			}
		}

		$locationList = [];
		try {
			$locationsData = $this->request('/api/admin/locations', 'get', ['page' => 1, 'per_page' => 100]);
			foreach (($locationsData['locations']['data'] ?? []) as $loc) {
				$locationList[$loc['uuid']] = $loc['name'];
			}
		} catch (Exception $e) {
			// Ignore
		}

		$nodeList = ['' => '-- Auto (use locations) --'];
		try {
			$nodesData = $this->request('/api/admin/nodes', 'get', ['page' => 1, 'per_page' => 100]);
			foreach (($nodesData['nodes']['data'] ?? []) as $node) {
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
					} else if (strtolower($value) === 'true' || strtolower($value) === 'false') {
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

	/**
	 * Normalize Calagopus API collections, because egg variables may come as:
	 * - [ ...variables ]
	 * - ['data' => [ ...variables ]]
	 * - ['variables' => [ ...variables ]]
	 * - a single variable object
	 */
	private function normalizeCollection(mixed $collection): array
	{
		if (!is_array($collection)) {
			return [];
		}

		if (isset($collection['env_variable']) || isset($collection['environment_variable'])) {
			return [$collection];
		}

		if (isset($collection['data']) && is_array($collection['data'])) {
			return $this->normalizeCollection($collection['data']);
		}

		if (isset($collection['variables']) && is_array($collection['variables'])) {
			return $this->normalizeCollection($collection['variables']);
		}

		return array_values(array_filter($collection, 'is_array'));
	}

	private function extractEggVariables(array $eggData, array $egg): array
	{
		$candidates = [
			$egg['variables'] ?? null,
			$eggData['variables'] ?? null,
			$egg['egg_variables'] ?? null,
			$eggData['egg_variables'] ?? null,
			$eggData['egg']['variables'] ?? null,
		];

		foreach ($candidates as $candidate) {
			$variables = $this->normalizeCollection($candidate);
			if (!empty($variables)) {
				return $variables;
			}
		}

		return [];
	}

	private function reservedSettingKeys(): array
	{
		return [
			'host',
			'api_key',
			'default_language',
			'oauth_provider_uuid',
			'nest_uuid',
			'egg_uuid',
			'node_uuid',
			'location_uuid',
			'location_uuids',
			'memory',
			'swap',
			'disk',
			'cpu',
			'memory_overhead',
			'io_weight',
			'allocations_limit',
			'database_limit',
			'backup_limit',
			'schedule_limit',
			'custom_feature_limits',
			'docker_image',
			'startup_command',
			'server_name_prefix',
			'server_name',
			'custom_server_name',
			'skip_installer',
			'start_on_completion',
			'hugepages_passthrough',
			'kvm_passthrough',
			'pinned_cpus',
			'backup_configuration_uuid',
			'product_id',
			'service_id',
			'price_id',
			'quantity',
			'billing_cycle',
			'configurable_options',
			'properties',
			'settings',
		];
	}

	private function looksLikeEggEnvVariable(string $key): bool
	{
		return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/', $key);
	}

	private function addVariableToPayload(array &$variables, array &$addedKeys, string $envKey, $value): void
	{
		$envKey = trim($envKey);
		if ($envKey === '') {
			return;
		}

		$normalized = strtoupper($envKey);
		$variables[] = [
			'env_variable' => $envKey,
			'value' => $this->stringifySettingValue($value),
		];
		$addedKeys[$normalized] = count($variables) - 1;
	}

	private function buildEggVariablesPayload(array $eggData, array $egg, array $settings): array
	{
		$variables = [];
		$addedKeys = [];

		// First path: if the API response includes egg variables, send every real egg variable,
		// using Paymenter values when present and egg defaults otherwise.
		foreach ($this->extractEggVariables($eggData, $egg) as $var) {
			$envKey = $var['env_variable'] ?? $var['environment_variable'] ?? null;
			if (!$envKey) {
				continue;
			}

			$value = null;
			if (!$this->getSettingValue($settings, $envKey, $value)) {
				$value = $var['default_value'] ?? '';
			}

			$this->addVariableToPayload($variables, $addedKeys, (string) $envKey, $value);
		}

		// Fallback path: some Calagopus egg endpoints do not return variables.
		// In that case, pass any Paymenter setting/config-option that looks like an egg env var.
		// Calagopus matches these by env_variable against the egg's real variables and uses
		// egg defaults for anything not provided, so this stays generic and egg-agnostic.
		$reserved = array_flip(array_map('strtolower', $this->reservedSettingKeys()));

		foreach ($settings as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}

			if (isset($reserved[strtolower($key)])) {
				continue;
			}

			if (!$this->looksLikeEggEnvVariable($key)) {
				continue;
			}

			if ($value === null || $value === '') {
				continue;
			}

			$normalized = strtoupper($key);
			if (isset($addedKeys[$normalized])) {
				// The key was already added from a fetched egg variable. Replace the value
				// with the explicit Paymenter value, preserving the real egg env name.
				$variables[$addedKeys[$normalized]]['value'] = $this->stringifySettingValue($value);
				continue;
			}

			$this->addVariableToPayload($variables, $addedKeys, $key, $value);
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
		try {
			$response = $this->request('/api/admin/servers/external/' . (string) $serviceId);
			return $response['server'] ?? null;
		} catch (Exception $e) {
			if ($failIfNotFound) {
				throw new Exception('Server not found on the panel.');
			}
			return null;
		}
	}

	/**
	 * Find or create a Calagopus panel user for the given service user.
	 * Handles 409 (email/username already exists) by searching and linking.
	 */
	private function findOrCreateUser($orderUser): array
	{
		// Try lookup by oauth identifier if configured
		$oauthProviderUuid = $this->config('oauth_provider_uuid');

		if ($oauthProviderUuid) {
			try {
				$response = $this->request('/api/admin/oauth-providers/' . $oauthProviderUuid . '/users/identifier/' . (string) $orderUser->id);
				if (isset($response['user_oauth_link']['user'])) {
					return $response['user_oauth_link']['user'];
				}
			} catch (Exception $e) {
				// Not found — continue to other lookups
			}
		}

		// Try lookup by external ID
		try {
			$response = $this->request('/api/admin/users/external/' . (string) $orderUser->id);
			if (isset($response['user'])) {
				// Add OAuth link if configured
				if ($oauthProviderUuid) {
					try {
						$this->request('/api/admin/users/' . $response['user']['uuid'] . '/oauth-links', 'post', [
							'oauth_provider_uuid' => $oauthProviderUuid,
							'identifier' => (string) $orderUser->id,
						]);
					} catch (Exception $e) {
						// Ignore errors here — not critical if linking fails
					}
				}

				return $response['user'];
			}
		} catch (Exception $e) {
			// Not found — continue to create
		}

		// Generate a username
		$baseName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(Str::transliterate($orderUser->name ?? 'user')));
		if (strlen($baseName) < 3) {
			$baseName = 'user';
		}

		$idStr = (string)$orderUser->id;
		$maxNameLen = max(0, 14 - strlen($idStr));

		$username = substr($baseName, 0, $maxNameLen) . '_' . $idStr;

		// Try creating the user
		try {
			$response = $this->request('/api/admin/users', 'post', [
				'external_id' => (string) $orderUser->id,
				'username' => $username,
				'email' => $orderUser->email,
				'name_first' => $orderUser->first_name ?? $orderUser->name ?? 'User',
				'name_last' => $orderUser->last_name ?? '',
				'admin' => false,
				'send_email' => true,
				'language' => $this->config('default_language') ?: 'en',
			]);

			if ($oauthProviderUuid) {
				try {
					$this->request('/api/admin/users/' . $response['user']['uuid'] . '/oauth-links', 'post', [
						'oauth_provider_uuid' => $oauthProviderUuid,
						'identifier' => (string) $orderUser->id,
					]);
				} catch (Exception $e) {
					// Ignore errors here — not critical if linking fails
				}
			}

			return $response['user'];
		} catch (Exception $e) {
			// Only handle 409 — rethrow anything else
			if (!str_contains($e->getMessage(), '409')) {
				throw $e;
			}
		}

		// 409 — user exists, search by email
		$searchResponse = $this->request('/api/admin/users', 'get', [
			'page' => 1,
			'per_page' => 10,
			'search' => $orderUser->email,
		]);

		$matched = null;
		foreach (($searchResponse['users']['data'] ?? []) as $user) {
			if (strcasecmp($user['email'] ?? '', $orderUser->email) === 0) {
				$matched = $user;
				break;
			}
		}

		// Fallback: search by username
		if (!$matched) {
			$searchResponse = $this->request('/api/admin/users', 'get', [
				'page' => 1,
				'per_page' => 10,
				'search' => $username,
			]);
			foreach (($searchResponse['users']['data'] ?? []) as $user) {
				if (strcasecmp($user['username'] ?? '', $username) === 0) {
					$matched = $user;
					break;
				}
			}
		}

		if (!$matched) {
			throw new Exception('User with this email/username already exists on the panel but could not be found via search.');
		}

		// Link the existing user by setting external_id
		$this->request('/api/admin/users/' . $matched['uuid'], 'patch', [
			'external_id' => (string) $orderUser->id,
		]);

		if ($oauthProviderUuid) {
			try {
				$this->request('/api/admin/users/' . $matched['uuid'] . '/oauth-links', 'post', [
					'oauth_provider_uuid' => $oauthProviderUuid,
					'identifier' => (string) $orderUser->id,
				]);
			} catch (Exception $e) {
				// Ignore errors here — not critical if linking fails
			}
		}

		return $matched;
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

		$eggData = $this->request('/api/admin/nests/' . $nestUuid . '/eggs/' . $eggUuid, 'get', ['include' => 'variables']);
		$egg = $eggData['egg'];

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
			'databases'   => (int) ($settings['database_limit'] ?? 0),
			'backups'     => (int) ($settings['backup_limit'] ?? 0),
			'schedules'   => (int) ($settings['schedule_limit'] ?? 0),
		];
		$customLimits = $this->parseCustomFeatureLimits($settings['custom_feature_limits'] ?? '');
		$featureLimits = array_merge($featureLimits, $customLimits);

		$variables = $this->buildEggVariablesPayload($eggData, $egg, $settings);

		$ioWeight = isset($settings['io_weight']) && $settings['io_weight'] !== '' ? (int) $settings['io_weight'] : null;

		$serverPayload = [
			'owner_uuid' => $panelUser['uuid'],
			'egg_uuid' => $eggUuid,
			'start_on_completion' => $this->boolSetting($settings, 'start_on_completion'),
			'skip_installer' => $this->boolSetting($settings, 'skip_installer'),
			'external_id' => (string) $service->id,
			'name' => $serverName,
			'limits' => [
				'cpu'             => (int) ($settings['cpu'] ?? 100),
				'memory'          => (int) ($settings['memory'] ?? 1024),
				'memory_overhead' => (int) ($settings['memory_overhead'] ?? 0),
				'swap'            => (int) ($settings['swap'] ?? 0),
				'disk'            => (int) ($settings['disk'] ?? 10240),
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
			$allocResponse = $this->request('/api/admin/nodes/' . $nodeUuid . '/allocations/available', 'get', [
				'page' => 1,
				'per_page' => 10,
			]);
			$allocations = $allocResponse['allocations']['data'] ?? [];
			if (empty($allocations)) {
				throw new Exception('No available allocations on the selected node.');
			}

			$serverPayload['node_uuid'] = $nodeUuid;
			$serverPayload['allocation_uuid'] = $allocations[0]['uuid'];
			$serverPayload['allocation_uuids'] = [];

			$response = $this->request('/api/admin/servers', 'post', $serverPayload);
			$server = $response['server'];
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

			$response = $this->request('/api/admin/servers/deploy', 'post', $serverPayload);
			$server = $response['server'];
		}

		return [
			'server_uuid' => $server['uuid'],
			'link' => rtrim($this->config('host'), '/') . '/server/' . $server['uuid'],
		];
	}

	public function suspendServer(Service $service, $settings, $properties)
	{
		$server = $this->findServer($service->id);

		$this->request('/api/admin/servers/' . $server['uuid'], 'patch', [
			'suspended' => true,
		]);

		return true;
	}

	public function unsuspendServer(Service $service, $settings, $properties)
	{
		$server = $this->findServer($service->id);

		$this->request('/api/admin/servers/' . $server['uuid'], 'patch', [
			'suspended' => false,
		]);

		return true;
	}

	public function terminateServer(Service $service, $settings, $properties)
	{
		$server = $this->findServer($service->id);

		$this->request('/api/admin/servers/' . $server['uuid'], 'delete', [
			'force' => false,
			'delete_backups' => true,
		]);

		return true;
	}

	public function upgradeServer(Service $service, $settings, $properties)
	{
		$server = $this->findServer($service->id);
		$settings = array_merge($settings, $properties);

		$featureLimits = [
			'allocations' => (int) ($settings['allocations_limit'] ?? 1),
			'databases'   => (int) ($settings['database_limit'] ?? 0),
			'backups'     => (int) ($settings['backup_limit'] ?? 0),
			'schedules'   => (int) ($settings['schedule_limit'] ?? 0),
		];
		$customLimits = $this->parseCustomFeatureLimits($settings['custom_feature_limits'] ?? '');
		$featureLimits = array_merge($featureLimits, $customLimits);

		$ioWeight = isset($settings['io_weight']) && $settings['io_weight'] !== '' ? (int) $settings['io_weight'] : null;

		$updateData = [
			'limits' => [
				'cpu'             => (int) ($settings['cpu'] ?? 100),
				'memory'          => (int) ($settings['memory'] ?? 1024),
				'memory_overhead' => (int) ($settings['memory_overhead'] ?? 0),
				'swap'            => (int) ($settings['swap'] ?? 0),
				'disk'            => (int) ($settings['disk'] ?? 10240),
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

		$this->request('/api/admin/servers/' . $server['uuid'], 'patch', $updateData);

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
				'url' => rtrim($this->config('host'), '/') . '/server/' . $server['uuid'],
			],
		];
	}
}
