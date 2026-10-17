<?php

namespace Paymenter\Extensions\Servers\Calagopus\Admin\Pages;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Servers\Calagopus\Support\PterodactylImporter;

class PterodactylImport extends Page
{
    protected string $view = 'calagopus::pages.pterodactyl-import';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-swap-box-line';

    protected static ?string $navigationLabel = 'Calagopus Pterodactyl Import';

    protected static ?string $title = 'Import from Pterodactyl';

    protected static ?string $slug = 'calagopus/pterodactyl-import';

    public ?int $sourceId = null;

    public ?int $targetId = null;

    public bool $recheckMigrated = false;

    public ?array $results = null;

    public bool $resultsDryRun = true;

    public function booted(): void
    {
        View::addNamespace('calagopus', dirname(__DIR__, 2) . '/resources/views');
    }

    public function mount(): void
    {
        $this->sourceId = $this->sources()->keys()->first();
        $this->targetId = $this->targets()->keys()->first();
    }

    public static function canAccess(): bool
    {
        return Auth::user()->hasPermission('admin.servers.update') && Auth::user()->hasPermission('admin.products.update');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Server::where('extension', 'Pterodactyl')->exists() && Server::where('extension', 'Calagopus')->exists();
    }

    public function sources(): Collection
    {
        return Server::where('extension', 'Pterodactyl')->pluck('name', 'id');
    }

    public function targets(): Collection
    {
        return Server::where('extension', 'Calagopus')->pluck('name', 'id');
    }

    public function getCounts(): array
    {
        $source = $this->sourceId ? Server::where('extension', 'Pterodactyl')->find($this->sourceId) : null;

        return $source ? PterodactylImporter::counts($source) : ['products' => 0, 'services' => 0];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sourceId', 'targetId', 'recheckMigrated'], true)) {
            $this->results = null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('ri-eye-line')
                ->color('gray')
                ->disabled(fn () => !$this->sourceId || !$this->targetId)
                ->action(fn () => $this->runImport(dryRun: true)),
            Action::make('import')
                ->label('Import')
                ->icon('ri-download-2-line')
                ->requiresConfirmation()
                ->modalHeading('Import from Pterodactyl')
                ->modalDescription('Moves every product that can be matched to the Calagopus server, rewriting its settings, and links the services and their owners on the panel. Products are not moved back if you change your mind, so run a preview first.')
                ->modalSubmitActionLabel('Import')
                ->disabled(fn () => !$this->sourceId || !$this->targetId)
                ->action(fn () => $this->runImport(dryRun: false)),
        ];
    }

    private function runImport(bool $dryRun): void
    {
        $source = Server::where('extension', 'Pterodactyl')->find($this->sourceId);
        $target = Server::where('extension', 'Calagopus')->find($this->targetId);

        if (!$source || !$target) {
            Notification::make()
                ->title('Select a Pterodactyl and a Calagopus server')
                ->warning()
                ->send();

            return;
        }

        try {
            $this->results = (new PterodactylImporter($source, $target, $dryRun))->run($this->recheckMigrated);
            $this->resultsDryRun = $dryRun;
        } catch (Exception $e) {
            Notification::make()
                ->title($dryRun ? 'Preview failed' : 'Import failed')
                ->body(e($e->getMessage()))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($dryRun ? 'Preview finished, nothing was changed' : 'Import finished')
            ->success()
            ->send();
    }
}
