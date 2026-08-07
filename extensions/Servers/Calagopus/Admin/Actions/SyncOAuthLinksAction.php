<?php

namespace Paymenter\Extensions\Servers\Calagopus\Admin\Actions;

use App\Helpers\ExtensionHelper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Calagopus\Admin\Pages\OAuthLinks;
use Paymenter\Extensions\Servers\Calagopus\Jobs\SyncOAuthLinksJob;
use Paymenter\Extensions\Servers\Calagopus\Support\OAuthSync;

class SyncOAuthLinksAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'syncCalagopusOAuthLinks';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sync All Users')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Sync OAuth Links')
            ->modalDescription('Links every customer with a Calagopus service to the configured OAuth provider. This runs in the background against the saved configuration, so save any pending changes first.')
            ->modalSubmitActionLabel('Start Sync')
            ->hidden(fn ($record) => empty($record))
            ->action(function ($record) {
                Gate::authorize('has-permission', 'admin.servers.update');

                $settings = ExtensionHelper::settingsToArray($record->settings);

                if (empty($settings['oauth_provider_uuid'])) {
                    Notification::make()
                        ->title('No OAuth Provider UUID saved')
                        ->body('Fill in the OAuth Provider UUID and save the server before syncing.')
                        ->warning()
                        ->send();

                    return;
                }

                if (OAuthSync::isRunning($record->id)) {
                    Notification::make()
                        ->title('A sync is already running')
                        ->body($this->pageLink('Follow its progress on the %s page.', $record->id))
                        ->warning()
                        ->send();

                    return;
                }

                OAuthSync::markQueued($record->id);
                SyncOAuthLinksJob::dispatch($record->id);

                Notification::make()
                    ->title('OAuth link sync queued')
                    ->body($this->pageLink('Progress is shown on the %s page.', $record->id))
                    ->success()
                    ->send();
            });
    }

    private function pageLink(string $sentence, int $serverId): HtmlString
    {
        $link = '<a class="text-primary-600" href="' . OAuthLinks::getUrl(['serverId' => $serverId]) . '">Calagopus OAuth Links</a>';

        return new HtmlString(sprintf($sentence, $link));
    }
}
