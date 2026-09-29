<?php

namespace Paymenter\Extensions\Servers\Calagopus\Support;

/**
 * Turns an admin API server object (and its live usage, if known) into what the client service
 * page shows, following the panel's own display rules.
 */
class ServerSummary
{
    private const INSTALL_STATES = [
        'installing' => ['Installing', 'info'],
        'install_failed' => ['Install Failed', 'danger'],
        'restoring_backup' => ['Restoring Backup', 'warning'],
        'backup_restore_failed' => ['Backup Restore Failed', 'danger'],
    ];

    private const POWER_STATES = [
        'running' => ['Running', 'success'],
        'starting' => ['Starting', 'warning'],
        'stopping' => ['Stopping', 'warning'],
        'offline' => ['Offline', 'muted'],
    ];

    public static function make(array $server, ?array $usage): array
    {
        return [
            'name' => $server['name'] ?? null,
            'egg' => $server['egg']['name'] ?? null,
            'nest' => $server['nest']['name'] ?? null,
            'location' => $server['node']['location']['name'] ?? null,
            'address' => self::formatAddress($server['allocation'] ?? null),
            'state' => self::describeState($server, $usage),
            'resources' => self::describeResources($server['limits'] ?? [], $usage),
            'uptime' => ($usage['state'] ?? null) === 'running' ? self::formatUptime((int) ($usage['uptime'] ?? 0)) : null,
            'includes' => self::describeIncludes($server['feature_limits'] ?? []),
        ];
    }

    /**
     * Feature limits as label/value pairs: the built-in ones first, then any numeric limit an
     * extension adds, labelled from its key since the panel has no display names for them.
     *
     * @return list<array{label: string, value: int|float}>
     */
    public static function describeIncludes(array $featureLimits): array
    {
        $builtIn = [
            'databases' => 'Databases',
            'backups' => 'Backups',
            'allocations' => 'Ports',
            'schedules' => 'Schedules',
        ];

        $includes = [];
        foreach ($builtIn as $key => $label) {
            $includes[] = ['label' => $label, 'value' => (int) ($featureLimits[$key] ?? 0)];
        }

        foreach ($featureLimits as $key => $value) {
            if (!isset($builtIn[$key]) && (is_int($value) || is_float($value))) {
                $includes[] = ['label' => self::humanizeKey((string) $key), 'value' => $value];
            }
        }

        return $includes;
    }

    /**
     * "max_players", "max-players" and "maxPlayers" all become "Max players".
     */
    public static function humanizeKey(string $key): string
    {
        $words = preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/[_\-\s]+/'], ['$1 $2', '$1 $2', ' '], $key);

        return ucfirst(strtolower(trim($words)));
    }

    /**
     * The server state the panel shows its owner, in the panel's order of precedence:
     * suspended, transferring, node maintenance, install/restore state, then the live power state.
     *
     * @return array{key: string, label: string, tone: string}|null
     */
    public static function describeState(array $server, ?array $usage): ?array
    {
        [$key, $state] = match (true) {
            !empty($server['is_suspended']) => ['suspended', ['Suspended', 'danger']],
            !empty($server['is_transferring']) => ['transferring', ['Transferring', 'info']],
            !empty($server['node']['maintenance_enabled']) => ['maintenance', ['Node Maintenance', 'danger']],
            isset(self::INSTALL_STATES[$server['status'] ?? '']) => [$server['status'], self::INSTALL_STATES[$server['status']]],
            isset(self::POWER_STATES[$usage['state'] ?? '']) => [$usage['state'], self::POWER_STATES[$usage['state']]],
            default => [null, null],
        };

        return $state === null ? null : ['key' => $key, 'label' => $state[0], 'tone' => $state[1]];
    }

    /**
     * Memory, disk and CPU as "used" (live, if known) and "limit" display strings, plus the used
     * percentage when both are known. Memory and CPU use only count while the server is up; disk
     * use is shown whatever the power state.
     */
    public static function describeResources(array $limits, ?array $usage): array
    {
        $isUp = in_array($usage['state'] ?? null, ['running', 'starting', 'stopping'], true);

        $resources = [
            'memory' => [(int) ($limits['memory'] ?? 0) * 1048576, $isUp && isset($usage['memory_bytes']) ? (int) $usage['memory_bytes'] : null, fn (float $v): string => self::formatBytes($v)],
            'disk' => [(int) ($limits['disk'] ?? 0) * 1048576, isset($usage['disk_bytes']) ? (int) $usage['disk_bytes'] : null, fn (float $v): string => self::formatBytes($v)],
            'cpu' => [(int) ($limits['cpu'] ?? 0), $isUp && isset($usage['cpu_absolute']) ? (float) $usage['cpu_absolute'] : null, fn (float $v): string => round($v, 1) . '%'],
        ];

        $result = [];
        foreach ($resources as $key => [$limit, $used, $format]) {
            $result[$key] = [
                'used' => $used !== null ? $format($used) : null,
                'limit' => $limit > 0 ? $format($limit) : 'Unlimited',
                'percent' => $used !== null && $limit > 0 ? (int) min(100, round($used / $limit * 100)) : null,
            ];
        }

        return $result;
    }

    /**
     * The primary allocation as host:port, preferring the alias like the panel does. IPv6 hosts
     * are bracketed so the result can be pasted into a game client.
     */
    public static function formatAddress(?array $allocation): ?string
    {
        if (empty($allocation)) {
            return null;
        }

        $host = ($allocation['ip_alias'] ?? '') ?: ($allocation['ip'] ?? '');
        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }

        return $host . ':' . ($allocation['port'] ?? '');
    }

    public static function formatBytes(float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }

    public static function formatUptime(int $milliseconds): string
    {
        $minutes = intdiv($milliseconds, 60000);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return match (true) {
            $days > 0 => sprintf('%dd %dh', $days, $hours),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes % 60),
            default => sprintf('%dm', $minutes),
        };
    }
}
