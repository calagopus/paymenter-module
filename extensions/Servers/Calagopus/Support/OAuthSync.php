<?php

namespace Paymenter\Extensions\Servers\Calagopus\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class OAuthSync
{
    public const RESULT_LINKED = 'linked';

    public const RESULT_ALREADY_LINKED = 'already_linked';

    public const RESULT_NO_PANEL_USER = 'no_panel_user';

    public const RESULT_EMAIL_MISMATCH = 'email_mismatch';

    public const RESULT_EMAIL_UNVERIFIED = 'email_unverified';

    public const RESULT_FAILED = 'failed';

    /**
     * Matches SyncOAuthLinksJob::$timeout and $uniqueFor, so a run whose worker died or never
     * started stops blocking new ones at the same moment the queue's unique lock expires.
     */
    public const RUN_TIMEOUT = 3600;

    private const TTL = 60 * 60 * 24 * 30;

    /**
     * Customers owning at least one service backed by the given server extension record.
     *
     * @return Builder<User>
     */
    public static function users(int $serverId): Builder
    {
        return User::whereHas('services.product', fn (Builder $query) => $query->where('server_id', $serverId));
    }

    public static function state(int $serverId): array
    {
        return Cache::get(self::stateKey($serverId), []);
    }

    public static function putState(int $serverId, array $state): void
    {
        Cache::put(self::stateKey($serverId), $state, self::TTL);
    }

    /**
     * Reset the run counters and mark a sweep as in progress. Called at dispatch so the UI
     * reflects the queued run immediately, and again by the job once a worker picks it up.
     */
    public static function markQueued(int $serverId): array
    {
        $state = [
            'status' => 'running',
            'started_at' => now()->timestamp,
            'finished_at' => null,
            'total' => self::users($serverId)->count(),
            'processed' => 0,
            self::RESULT_LINKED => 0,
            self::RESULT_ALREADY_LINKED => 0,
            self::RESULT_NO_PANEL_USER => 0,
            self::RESULT_EMAIL_MISMATCH => 0,
            self::RESULT_EMAIL_UNVERIFIED => 0,
            self::RESULT_FAILED => 0,
            'errors' => [],
        ];

        self::putState($serverId, $state);

        return $state;
    }

    public static function isRunning(int $serverId): bool
    {
        $state = self::state($serverId);

        if (($state['status'] ?? null) !== 'running') {
            return false;
        }

        return ($state['started_at'] ?? 0) > now()->timestamp - self::RUN_TIMEOUT;
    }

    public static function result(int $serverId, int $userId): ?string
    {
        return Cache::get(self::resultKey($serverId, $userId));
    }

    public static function putResult(int $serverId, int $userId, string $result): void
    {
        Cache::put(self::resultKey($serverId, $userId), $result, self::TTL);
    }

    public static function label(?string $result): string
    {
        return match ($result) {
            self::RESULT_LINKED => 'Linked',
            self::RESULT_ALREADY_LINKED => 'Already linked',
            self::RESULT_NO_PANEL_USER => 'No panel account',
            self::RESULT_EMAIL_MISMATCH => 'Email mismatch',
            self::RESULT_EMAIL_UNVERIFIED => 'Email unverified',
            self::RESULT_FAILED => 'Failed',
            default => 'Never synced',
        };
    }

    public static function color(?string $result): string
    {
        return match ($result) {
            self::RESULT_LINKED => 'success',
            self::RESULT_ALREADY_LINKED => 'info',
            self::RESULT_NO_PANEL_USER, self::RESULT_EMAIL_MISMATCH, self::RESULT_EMAIL_UNVERIFIED => 'warning',
            self::RESULT_FAILED => 'danger',
            default => 'gray',
        };
    }

    private static function stateKey(int $serverId): string
    {
        return 'calagopus.oauth-sync.' . $serverId . '.state';
    }

    private static function resultKey(int $serverId, int $userId): string
    {
        return 'calagopus.oauth-sync.' . $serverId . '.result.' . $userId;
    }
}
