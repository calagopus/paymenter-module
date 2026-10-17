<?php

namespace Paymenter\Extensions\Servers\Calagopus\Support;

use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Product;
use App\Models\ProductUpgrade;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves products and services from Paymenter's Pterodactyl extension to the Calagopus extension, after the
 * panel itself was migrated with the Calagopus Pterodactyl importer. That importer keeps server, user, node,
 * nest and egg UUIDs and server external IDs, and names locations after their short name, so products are
 * matched through the Pterodactyl API and services keep working through their external ID (the service ID).
 */
class PterodactylImporter
{
    /**
     * Pterodactyl setting names a config option may use, and what the Calagopus extension calls them.
     */
    private const RENAMED_OPTIONS = [
        'io' => 'io_weight',
        'databases' => 'database_limit',
        'backups' => 'backup_limit',
        'additional_allocations' => 'allocations_limit (the total, so one more than before)',
        'cpu_pinning' => 'pinned_cpus (comma-separated cores, no ranges)',
        'skip_scripts' => 'skip_installer',
        'servername' => 'server_name',
        'egg_id' => 'egg_uuid (a Calagopus egg UUID)',
        'nest_id' => 'nest_uuid (a Calagopus nest UUID)',
        'node' => 'node_uuid (a Calagopus node UUID)',
        'location' => 'location_uuids (a Calagopus location UUID)',
        'oom_killer' => null,
        'dedicated_ip' => null,
        'port_array' => null,
    ];

    private CalagopusAPI $pterodactyl;

    private CalagopusAPI $calagopus;

    private array $catalogs = [];

    private array $checkedUsers = [];

    private array $results = [
        'products' => 0,
        'products_skipped' => 0,
        'services' => 0,
        'services_unmatched' => 0,
        'users_linked' => 0,
        'warnings' => [],
        'notes' => [],
    ];

    public function __construct(private Server $source, private Server $target, private bool $dryRun)
    {
        $sourceSettings = ExtensionHelper::settingsToArray($source->settings);
        $targetSettings = ExtensionHelper::settingsToArray($target->settings);

        $this->pterodactyl = new CalagopusAPI($sourceSettings['host'] ?? '', $sourceSettings['api_key'] ?? '', 'Pterodactyl');
        $this->calagopus = new CalagopusAPI($targetSettings['host'] ?? '', $targetSettings['api_key'] ?? '');
    }

    public static function counts(Server $source): array
    {
        return [
            'products' => Product::where('server_id', $source->id)->count(),
            'services' => Service::whereIn('product_id', Product::where('server_id', $source->id)->select('id'))->count(),
        ];
    }

    /**
     * Convert every product on the Pterodactyl server, then link the services of the converted products. With
     * $recheckMigrated, services of products already on the Calagopus server are linked again too, so services or
     * owners that could not be matched on an earlier run are retried.
     */
    public function run(bool $recheckMigrated): array
    {
        $productIds = [];

        foreach (Product::where('server_id', $this->source->id)->with('settings')->get() as $product) {
            $settings = $this->convertProduct($product);
            if ($settings === null) {
                $this->results['products_skipped']++;

                continue;
            }

            if (!$this->dryRun) {
                $this->saveProduct($product, $settings);
            }

            $productIds[] = $product->id;
            $this->results['products']++;
        }

        $this->warnUpgrades($productIds);
        $this->warnConfigOptions($productIds);

        if ($recheckMigrated) {
            $productIds = array_merge($productIds, Product::where('server_id', $this->target->id)->pluck('id')->all());
        }

        Service::whereIn('product_id', $productIds)->chunkById(100, function ($services) {
            foreach ($services as $service) {
                $this->importService($service);
                $this->results['services']++;
            }
        });

        return $this->results;
    }

    private function convertProduct(Product $product): ?array
    {
        $old = ExtensionHelper::settingsToArray($product->settings);
        $label = sprintf('Product #%d (%s)', $product->id, $product->name);

        [$nestUuid, $eggUuid] = $this->matchEgg((string) ($old['egg_id'] ?? ''));
        $nodeUuid = $this->matchNode((string) ($old['node'] ?? ''));

        $locationUuids = [];
        foreach ((array) ($old['location_ids'] ?? []) as $locationId) {
            $uuid = $this->matchLocation((string) $locationId);
            if ($uuid === null) {
                $this->results['notes'][] = sprintf('%s: Pterodactyl location #%s has no Calagopus location with the same name and was left out.', $label, $locationId);

                continue;
            }
            $locationUuids[] = $uuid;
        }

        if ($eggUuid === null || ($nodeUuid === null && empty($locationUuids))) {
            $this->results['notes'][] = sprintf(
                '%s was skipped: its %s could not be matched on the Calagopus panel.',
                $label,
                $eggUuid === null ? 'egg' : 'node and locations'
            );

            return null;
        }

        // Pterodactyl's allocation limit is the total, the Pterodactyl extension adds the ports it deploys with.
        $portArray = json_decode((string) ($old['port_array'] ?? ''), true);
        $deployedPorts = 1;
        if (is_array($portArray) && !empty($portArray)) {
            $deployedPorts = array_sum(array_map(fn ($ports) => is_array($ports) ? count($ports) : 1, $portArray));
            $this->results['notes'][] = sprintf('%s: its port array has no Calagopus equivalent, use egg configurations to assign ports.', $label);
        }

        return [
            'nest_uuid' => $nestUuid,
            'egg_uuid' => $eggUuid,
            'node_uuid' => $nodeUuid ?? '',
            'location_uuids' => $locationUuids,
            'memory' => (string) ($old['memory'] ?? 1024),
            'swap' => (string) ($old['swap'] ?? 0),
            'disk' => (string) ($old['disk'] ?? 10240),
            'cpu' => (string) ($old['cpu'] ?? 100),
            'memory_overhead' => '0',
            'io_weight' => (string) ($old['io'] ?? ''),
            'allocations_limit' => (string) ((int) ($old['additional_allocations'] ?? 0) + $deployedPorts),
            'database_limit' => (string) ($old['databases'] ?? 0),
            'backup_limit' => (string) ($old['backups'] ?? 0),
            // Pterodactyl has no schedule limit, the panel importer gives migrated servers at least 10.
            'schedule_limit' => '10',
            'custom_feature_limits' => '',
            'docker_image' => '',
            'startup_command' => '',
            'server_name_prefix' => '',
            'skip_installer' => self::flag($old['skip_scripts'] ?? false),
            'start_on_completion' => self::flag($old['start_on_completion'] ?? false),
            'hugepages_passthrough' => '0',
            'kvm_passthrough' => '0',
            'pinned_cpus' => self::expandCpuRanges((string) ($old['cpu_pinning'] ?? '')),
            'backup_configuration_uuid' => '',
        ];
    }

    /**
     * Replace the product's settings the way the product editor stores them, and move it to the Calagopus server.
     */
    private function saveProduct(Product $product, array $settings): void
    {
        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = [
                'key' => $key,
                'settingable_id' => $product->id,
                'settingable_type' => $product->getMorphClass(),
                'type' => is_array($value) ? 'array' : 'string',
                'value' => is_array($value) ? json_encode($value) : $value,
            ];
        }

        DB::transaction(function () use ($product, $rows) {
            $product->settings()->delete();
            $product->settings()->upsert($rows, uniqueBy: ['key', 'settingable_id', 'settingable_type'], update: ['type', 'value']);
            $product->update(['server_id' => $this->target->id]);
        });
    }

    private function importService(Service $service): void
    {
        try {
            $server = $this->findServer($service);
        } catch (CalagopusAPIException $e) {
            $this->results['notes'][] = sprintf('Service #%d could not be looked up: %s', $service->id, $e->getMessage());

            return;
        }

        if (!$server) {
            if (in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                $this->results['services_unmatched']++;
                $this->results['notes'][] = sprintf('Service #%d: no matching server was found on the Calagopus panel.', $service->id);
            }

            return;
        }

        if ($service->user) {
            try {
                $this->linkOwner($server, $service->user);
            } catch (CalagopusAPIException $e) {
                $this->results['notes'][] = sprintf('User #%d could not be linked to their panel account: %s', $service->user->id, $e->getMessage());
            }
        }
    }

    /**
     * The panel importer keeps external IDs, so the server is normally found directly. Otherwise the server is
     * looked up on Pterodactyl and found on Calagopus by its UUID, then given the service ID as external ID.
     */
    private function findServer(Service $service): ?array
    {
        $server = $this->calagopus->getServerByExternalId((string) $service->id);
        if ($server) {
            return $server;
        }

        try {
            $uuid = $this->pterodactyl->get('/api/application/servers/external/' . $service->id)['attributes']['uuid'] ?? null;
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        $server = $uuid ? $this->calagopus->getServer($uuid) : null;
        if (!$server) {
            return null;
        }

        if (!empty($server['external_id'])) {
            $this->results['notes'][] = sprintf('Service #%d: its server %s already has the external ID "%s", so it was not linked.', $service->id, $server['uuid'], $server['external_id']);

            return null;
        }

        if (!$this->dryRun) {
            $this->calagopus->updateServer($server['uuid'], ['external_id' => (string) $service->id]);
        }

        return $server;
    }

    /**
     * The Pterodactyl extension matched panel users by email only. Give the server owner the customer's ID as
     * external ID when the owner is that customer's account, following the same rules as provisioning: the
     * customer's email has to be verified, and no other panel user may already be linked to the customer.
     */
    private function linkOwner(array $server, User $user): void
    {
        $owner = $server['owner'] ?? [];
        $externalId = (string) $user->id;

        if (($owner['external_id'] ?? null) === $externalId || isset($this->checkedUsers[$user->id])) {
            return;
        }
        $this->checkedUsers[$user->id] = true;

        $reason = match (true) {
            empty($owner['uuid']) => null,
            !empty($owner['external_id']) => 'is already linked to external ID "' . $owner['external_id'] . '"',
            !empty($owner['admin']) => 'is a panel admin',
            strcasecmp($owner['email'] ?? '', $user->email) !== 0 => 'has a different email address',
            !$user->hasVerifiedEmail() => 'can only be linked once the customer has verified their email address',
            default => false,
        };

        if ($reason === false) {
            $linked = $this->calagopus->getUserByExternalId($externalId);
            if ($linked && $linked['uuid'] !== $owner['uuid']) {
                $reason = 'is not the panel user ' . $linked['uuid'] . ' already linked to this customer';
            }
        }

        if ($reason !== false) {
            if ($reason !== null) {
                $this->results['notes'][] = sprintf('User #%d (%s) was not linked: the owner of server %s %s.', $user->id, $user->email, $server['uuid'], $reason);
            }

            return;
        }

        if (!$this->dryRun) {
            $this->calagopus->updateUser($owner['uuid'], ['external_id' => $externalId]);
        }
        $this->results['users_linked']++;
    }

    /**
     * Upgrades run on the extension of the product being upgraded to, so a product on one server upgrading to a
     * product on the other would break the upgrade.
     */
    private function warnUpgrades(array $productIds): void
    {
        $linked = ProductUpgrade::whereIn('product_id', $productIds)->pluck('upgrade_id')
            ->merge(ProductUpgrade::whereIn('upgrade_id', $productIds)->pluck('product_id'));

        $stragglers = Product::whereIn('id', $linked)
            ->whereNotIn('id', $productIds)
            ->where('server_id', $this->source->id)
            ->get();

        foreach ($stragglers as $product) {
            $this->results['warnings'][] = sprintf(
                'Product #%d (%s) is an upgrade path of a migrated product but is still on Pterodactyl. Upgrades between them will fail until it is migrated too.',
                $product->id,
                $product->name
            );
        }
    }

    /**
     * Config option values override product settings by name, and the Calagopus extension names several of them
     * differently. Such options keep being sold but no longer do anything, and upgrades reset those limits.
     */
    private function warnConfigOptions(array $productIds): void
    {
        $options = ConfigOption::whereIn('env_variable', array_keys(self::RENAMED_OPTIONS))
            ->whereIn('id', ConfigOptionProduct::whereIn('product_id', $productIds)->select('config_option_id'))
            ->get();

        foreach ($options as $option) {
            $services = ServiceConfig::where('config_option_id', $option->id)
                ->where('configurable_type', (new Service)->getMorphClass())
                ->whereIn('configurable_id', Service::whereIn('product_id', $productIds)->select('id'))
                ->count();

            $replacement = self::RENAMED_OPTIONS[$option->env_variable];
            $this->results['warnings'][] = sprintf(
                'Config option #%d (%s) uses "%s", which the Calagopus extension %s. It is set on %d migrated service(s).',
                $option->id,
                $option->name,
                $option->env_variable,
                $replacement ? 'calls "' . $replacement . '"' : 'does not support',
                $services
            );
        }
    }

    /**
     * Match a Pterodactyl egg ID to a Calagopus nest and egg UUID, by egg UUID first, then by nest and egg name.
     */
    private function matchEgg(string $eggId): array
    {
        if ($eggId === '') {
            return [null, null];
        }

        $source = null;
        foreach ($this->pterodactylCatalog('/api/application/nests', ['include' => 'eggs']) as $nest) {
            foreach ($nest['relationships']['eggs']['data'] ?? [] as $egg) {
                if ((string) ($egg['attributes']['id'] ?? '') === $eggId) {
                    $source = ['nest' => $nest, 'egg' => $egg['attributes']];

                    break 2;
                }
            }
        }
        if ($source === null) {
            return [null, null];
        }

        $this->catalogs['calagopus_eggs'] ??= array_map(
            fn ($nest) => ['nest' => $nest, 'eggs' => $this->calagopus->getEggs($nest['uuid'])],
            $this->calagopus->getNests()
        );

        foreach ([true, false] as $byUuid) {
            foreach ($this->catalogs['calagopus_eggs'] as $entry) {
                foreach ($entry['eggs'] as $egg) {
                    $matches = $byUuid
                        ? ($egg['uuid'] ?? null) === ($source['egg']['uuid'] ?? false)
                        : self::sameName($entry['nest']['name'] ?? '', $source['nest']['name'] ?? '') && self::sameName($egg['name'] ?? '', $source['egg']['name'] ?? '');
                    if ($matches) {
                        return [$entry['nest']['uuid'], $egg['uuid']];
                    }
                }
            }
        }

        return [null, null];
    }

    /**
     * Match a Pterodactyl node ID to a Calagopus node UUID, by UUID first, then by name.
     */
    private function matchNode(string $nodeId): ?string
    {
        if ($nodeId === '') {
            return null;
        }

        $source = null;
        foreach ($this->pterodactylCatalog('/api/application/nodes') as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                $source = $node;

                break;
            }
        }
        if ($source === null) {
            return null;
        }

        $this->catalogs['calagopus_nodes'] ??= $this->calagopus->getNodes();
        foreach ([true, false] as $byUuid) {
            foreach ($this->catalogs['calagopus_nodes'] as $node) {
                if ($byUuid ? ($node['uuid'] ?? null) === ($source['uuid'] ?? false) : self::sameName($node['name'] ?? '', $source['name'] ?? '')) {
                    return $node['uuid'];
                }
            }
        }

        return null;
    }

    /**
     * Match a Pterodactyl location ID to a Calagopus location UUID by name. The panel importer names locations
     * after their short name, so that is tried before the long name.
     */
    private function matchLocation(string $locationId): ?string
    {
        $names = [];
        foreach ($this->pterodactylCatalog('/api/application/locations') as $location) {
            if ((string) ($location['id'] ?? '') === $locationId) {
                $names = array_filter([trim($location['short'] ?? ''), trim($location['long'] ?? '')]);

                break;
            }
        }

        $this->catalogs['calagopus_locations'] ??= $this->calagopus->getLocations();
        foreach ($names as $name) {
            foreach ($this->catalogs['calagopus_locations'] as $location) {
                if (self::sameName($location['name'] ?? '', $name)) {
                    return $location['uuid'];
                }
            }
        }

        return null;
    }

    /**
     * Fetch and cache a paginated list from the Pterodactyl application API.
     *
     * @return array list of item attributes
     */
    private function pterodactylCatalog(string $endpoint, array $query = []): array
    {
        if (isset($this->catalogs[$endpoint])) {
            return $this->catalogs[$endpoint];
        }

        $items = [];
        $page = 1;
        do {
            $response = $this->pterodactyl->get($endpoint, $query + ['page' => $page, 'per_page' => 100]);
            foreach ($response['data'] ?? [] as $item) {
                $items[] = $item['attributes'] ?? [];
            }

            $totalPages = (int) ($response['meta']['pagination']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return $this->catalogs[$endpoint] = $items;
    }

    private static function sameName(string $a, string $b): bool
    {
        return trim($a) !== '' && strcasecmp(trim($a), trim($b)) === 0;
    }

    private static function flag(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    /**
     * Pterodactyl accepts ranges such as "0,2-4", the Calagopus extension only single cores.
     */
    private static function expandCpuRanges(string $raw): string
    {
        $cpus = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)-(\d+)$/', $part, $range)) {
                array_push($cpus, ...range((int) $range[1], max((int) $range[1], (int) $range[2])));
            } elseif (ctype_digit($part)) {
                $cpus[] = (int) $part;
            }
        }

        return implode(',', array_unique($cpus));
    }
}
