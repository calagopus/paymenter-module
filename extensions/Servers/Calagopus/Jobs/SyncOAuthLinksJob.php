<?php

namespace Paymenter\Extensions\Servers\Calagopus\Jobs;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\User;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Calagopus\Support\OAuthSync;
use Throwable;

class SyncOAuthLinksJob implements ShouldBeUnique, ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public int $timeout = OAuthSync::RUN_TIMEOUT;

	public int $tries = 1;

	public int $uniqueFor = OAuthSync::RUN_TIMEOUT;

	private const MAX_REPORTED_ERRORS = 10;

	public function __construct(public int $serverId) {}

	public function uniqueId(): string
	{
		return (string) $this->serverId;
	}

	public function handle(): void
	{
		$server = Server::find($this->serverId);

		if (!$server) {
			return;
		}

		$extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);

		$state = OAuthSync::markQueued($this->serverId);

		OAuthSync::users($this->serverId)->chunkById(100, function (Collection $users) use ($extension, &$state) {
			foreach ($users as $user) {
				$state[$this->syncUser($extension, $user, $state)]++;
				$state['processed']++;
			}

			OAuthSync::putState($this->serverId, $state);
		});

		$state['status'] = 'finished';
		$state['finished_at'] = now()->timestamp;

		OAuthSync::putState($this->serverId, $state);
	}

	public function failed(?Throwable $exception): void
	{
		$state = OAuthSync::state($this->serverId);
		$state['status'] = 'failed';
		$state['finished_at'] = now()->timestamp;
		$state['errors'][] = $exception?->getMessage() ?? 'Unknown error';

		OAuthSync::putState($this->serverId, $state);
	}

	private function syncUser($extension, User $user, array &$state): string
	{
		try {
			$result = $extension->syncOAuthLink($user);
		} catch (Exception $e) {
			$result = OAuthSync::RESULT_FAILED;

			if (count($state['errors']) < self::MAX_REPORTED_ERRORS) {
				$state['errors'][] = $user->email . ': ' . $e->getMessage();
			}
		}

		OAuthSync::putResult($this->serverId, $user->id, $result);

		return $result;
	}
}
