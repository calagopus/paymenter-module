<?php

namespace Paymenter\Extensions\Servers\Calagopus\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\User;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Servers\Calagopus\Jobs\SyncOAuthLinksJob;
use Paymenter\Extensions\Servers\Calagopus\Support\OAuthSync;

class OAuthLinks extends Page implements HasTable
{
	use InteractsWithTable;

	protected string $view = 'calagopus::pages.oauth-links';

	protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

	protected static string|\BackedEnum|null $navigationIcon = 'ri-link';

	protected static ?string $navigationLabel = 'Calagopus OAuth Links';

	protected static ?string $title = 'Calagopus OAuth Links';

	protected static ?string $slug = 'calagopus/oauth-links';

	#[Url]
	public ?int $serverId = null;

	public function booted(): void
	{
		View::addNamespace('calagopus', dirname(__DIR__, 2) . '/resources/views');
	}

	public function mount(): void
	{
		$servers = $this->servers();

		if (!$this->serverId || !$servers->has($this->serverId)) {
			$this->serverId = $servers->keys()->first();
		}
	}

	public static function canAccess(): bool
	{
		return Auth::user()->hasPermission('admin.servers.update');
	}

	public static function shouldRegisterNavigation(): bool
	{
		return Server::where('extension', 'Calagopus')->exists();
	}

	public function servers(): Collection
	{
		return Server::where('extension', 'Calagopus')->pluck('name', 'id');
	}

	public function getSyncState(): array
	{
		return $this->serverId ? OAuthSync::state($this->serverId) : [];
	}

	protected function getHeaderActions(): array
	{
		return [
			Action::make('syncAll')
				->label('Sync All Users')
				->icon('ri-refresh-line')
				->requiresConfirmation()
				->modalHeading('Sync OAuth Links')
				->modalDescription('Links every customer with a Calagopus service to the configured OAuth provider. This runs in the background.')
				->modalSubmitActionLabel('Start Sync')
				->disabled(fn () => !$this->serverId || OAuthSync::isRunning($this->serverId))
				->action(function () {
					if (!$this->providerUuid()) {
						Notification::make()
							->title('No OAuth Provider UUID saved')
							->body('Set the OAuth Provider UUID on the server configuration before syncing.')
							->warning()
							->send();

						return;
					}

					OAuthSync::markQueued($this->serverId);
					SyncOAuthLinksJob::dispatch($this->serverId);

					Notification::make()
						->title('OAuth link sync queued')
						->success()
						->send();
				}),
		];
	}

	public function table(Table $table): Table
	{
		return $table
			->query(fn () => $this->serverId
				? OAuthSync::users($this->serverId)
				: User::query()->whereRaw('1 = 0'))
			->description('Customers owning at least one service on this Calagopus server.')
			->poll(fn () => $this->serverId && OAuthSync::isRunning($this->serverId) ? '5s' : null)
			->defaultSort('id')
			->columns([
				TextColumn::make('id')
					->label('User ID')
					->sortable(),
				TextColumn::make('first_name')->searchable()->sortable(),
				TextColumn::make('last_name')->searchable()->sortable(),
				TextColumn::make('email')->searchable()->sortable(),
				TextColumn::make('oauth_link')
					->label('Last Sync Result')
					->badge()
					->state(fn (User $record) => OAuthSync::result($this->serverId, $record->id))
					->formatStateUsing(fn (?string $state) => OAuthSync::label($state))
					->color(fn (?string $state) => OAuthSync::color($state)),
			])
			->recordActions([
				Action::make('sync')
					->label('Sync')
					->icon('ri-refresh-line')
					->requiresConfirmation()
					->modalHeading('Sync OAuth Link')
					->modalDescription(fn (User $record) => 'Link ' . $record->email . ' to the configured OAuth provider on the panel.')
					->modalSubmitActionLabel('Sync')
					->action(fn (User $record) => $this->syncUser($record)),
			]);
	}

	private function syncUser(User $user): void
	{
		if (!$this->providerUuid()) {
			Notification::make()
				->title('No OAuth Provider UUID saved')
				->body('Set the OAuth Provider UUID on the server configuration before syncing.')
				->warning()
				->send();

			return;
		}

		$server = Server::find($this->serverId);

		try {
			$result = ExtensionHelper::getExtension('server', $server->extension, $server->settings)->syncOAuthLink($user);
		} catch (Exception $e) {
			OAuthSync::putResult($this->serverId, $user->id, OAuthSync::RESULT_FAILED);

			Notification::make()
				->title('Sync failed for ' . $user->email)
				->body($e->getMessage())
				->danger()
				->send();

			return;
		}

		OAuthSync::putResult($this->serverId, $user->id, $result);

		$notification = Notification::make()->title(OAuthSync::label($result) . ': ' . $user->email);

		match ($result) {
			OAuthSync::RESULT_LINKED => $notification->success(),
			OAuthSync::RESULT_ALREADY_LINKED => $notification->info(),
			default => $notification->warning(),
		};

		$notification->send();
	}

	private function providerUuid(): ?string
	{
		$server = $this->serverId ? Server::find($this->serverId) : null;

		if (!$server) {
			return null;
		}

		return ExtensionHelper::settingsToArray($server->settings)['oauth_provider_uuid'] ?? null;
	}
}
