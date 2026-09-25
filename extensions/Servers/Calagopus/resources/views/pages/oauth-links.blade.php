@php
    use Paymenter\Extensions\Servers\Calagopus\Support\OAuthSync;

    $servers = $this->servers();
    $state = $this->getSyncState();

    $isRunning = ($state['status'] ?? null) === 'running';
    $total = $state['total'] ?? 0;
    $processed = $state['processed'] ?? 0;
    $percent = $total > 0 ? min(100, round($processed / $total * 100)) : 0;

    $tiles = [
        [OAuthSync::RESULT_LINKED, 'Linked'],
        [OAuthSync::RESULT_ALREADY_LINKED, 'Already linked'],
        [OAuthSync::RESULT_NO_PANEL_USER, 'No panel account'],
        [OAuthSync::RESULT_EMAIL_MISMATCH, 'Email mismatch'],
        [OAuthSync::RESULT_EMAIL_UNVERIFIED, 'Email unverified'],
        [OAuthSync::RESULT_FAILED, 'Failed'],
    ];
@endphp

<x-filament-panels::page>
    @if ($servers->count() > 1)
        <x-filament::input.wrapper style="max-width: 24rem;">
            <x-filament::input.select wire:model.live="serverId">
                @foreach ($servers as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    @endif

    @if ($state)
        <x-filament::section
            :heading="$isRunning ? 'Sync running' : 'Last sync'"
            :description="$isRunning
                ? $processed . ' of ' . $total . ' customers processed'
                : 'Finished ' . \Carbon\Carbon::createFromTimestamp($state['finished_at'] ?? $state['started_at'])->diffForHumans()"
        >
            @if ($isRunning)
                <div style="height: 0.5rem; width: 100%; border-radius: 9999px; background-color: rgb(128 128 128 / 0.2); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="height: 100%; width: {{ $percent }}%; border-radius: 9999px; background-color: #3b82f6; transition: width .3s ease;"></div>
                </div>
            @endif

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1.25rem;">
                @foreach ($tiles as [$result, $label])
                    <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem;">
                        <span style="font-size: 1.5rem; line-height: 1; font-weight: 600;">{{ $state[$result] ?? 0 }}</span>
                        <x-filament::badge :color="OAuthSync::color($result)">{{ $label }}</x-filament::badge>
                    </div>
                @endforeach
            </div>

            @if (!empty($state['errors']))
                <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem; color: #ef4444;">
                    @foreach ($state['errors'] as $error)
                        <span>{{ $error }}</span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
