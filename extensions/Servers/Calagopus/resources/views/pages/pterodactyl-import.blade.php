@php
    $sources = $this->sources();
    $targets = $this->targets();
    $counts = $this->getCounts();

    $tiles = $results ? [
        ['products', $resultsDryRun ? 'Products to import' : 'Products imported', 'success'],
        ['products_skipped', 'Products skipped', 'warning'],
        ['services', 'Services checked', 'info'],
        ['services_unmatched', 'Services without a server', 'danger'],
        ['users_linked', $resultsDryRun ? 'Users to link' : 'Users linked', 'success'],
    ] : [];
@endphp

<x-filament-panels::page>
    <x-filament::section
        heading="Source and target"
        :description="'The Pterodactyl server has ' . $counts['products'] . ' product(s) with ' . $counts['services'] . ' service(s) left to import.'"
    >
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem;">
            <label style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem; font-weight: 500;">
                Pterodactyl server
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="sourceId">
                        @foreach ($sources as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem; font-weight: 500;">
                Calagopus server
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="targetId">
                        @foreach ($targets as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>

        <label style="display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; font-size: 0.875rem;">
            <x-filament::input.checkbox wire:model.live="recheckMigrated" />
            Also re-check services of products already on the Calagopus server, to retry servers and owners an earlier run could not link.
        </label>
    </x-filament::section>

    @if ($results)
        <x-filament::section
            :heading="$resultsDryRun ? 'Preview' : 'Import results'"
            :description="$resultsDryRun ? 'Nothing has been changed yet. Click Import to apply this.' : 'Anything skipped below can be fixed on the panel and imported by running the import again.'"
        >
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1.25rem;">
                @foreach ($tiles as [$key, $label, $color])
                    <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem;">
                        <span style="font-size: 1.5rem; line-height: 1; font-weight: 600;">{{ $results[$key] ?? 0 }}</span>
                        <x-filament::badge :color="$color">{{ $label }}</x-filament::badge>
                    </div>
                @endforeach
            </div>

            @if (!empty($results['warnings']))
                <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem; color: #f59e0b;">
                    @foreach ($results['warnings'] as $warning)
                        <span>{{ $warning }}</span>
                    @endforeach
                </div>
            @endif

            @if (!empty($results['notes']))
                <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem;">
                    @foreach ($results['notes'] as $note)
                        <span>{{ $note }}</span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
