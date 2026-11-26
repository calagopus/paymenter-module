@php
    // Only classes the default theme's compiled stylesheet already ships, as extensions can't add to it.
    $tones = [
        'success' => 'text-green-500',
        'danger' => 'text-red-500',
        'warning' => 'text-orange-500',
        'info' => 'text-info',
        'muted' => 'text-base/50',
    ];
@endphp

<div class="bg-background-secondary border border-neutral p-6 rounded-lg overflow-hidden">
    @if ($error)
        <p class="text-base/50">{{ __('calagopus::server.unable_to_load') }}</p>
    @elseif (!$summary)
        <p class="text-base/50">{{ __('calagopus::server.being_set_up') }}</p>
    @else
        <div class="flex flex-col md:flex-row justify-between gap-2">
            <div>
                <h4 class="text-lg font-semibold break-words">{{ $summary['name'] }}</h4>
                @if ($summary['egg'])
                    <span class="text-sm text-base/50">{{ $summary['egg'] }}@if ($summary['nest']) &middot; {{ $summary['nest'] }}@endif</span>
                @endif
            </div>
            @if ($summary['state'])
                <span class="font-semibold {{ $tones[$summary['state']['tone']] }}">{{ $summary['state']['label'] }}</span>
            @endif
        </div>

        <div class="grid md:grid-cols-2 gap-4 mt-4">
            <div class="overflow-hidden">
                <div class="flex flex-wrap items-center gap-x-2 text-base">
                    <span>{{ __('calagopus::server.address') }}:</span>
                    @if ($summary['address'])
                        <span class="font-mono text-base/50 break-words">{{ $summary['address'] }}</span>
                        <button type="button" class="text-sm font-semibold text-primary hover:underline cursor-pointer"
                            x-data="{ copied: false }" data-address="{{ $summary['address'] }}"
                            @click="navigator.clipboard.writeText($el.dataset.address); copied = true; setTimeout(() => copied = false, 2000)"
                            x-text="copied ? @js(__('calagopus::server.copied')) : @js(__('calagopus::server.copy'))">{{ __('calagopus::server.copy') }}</button>
                    @else
                        <span class="text-base/50">{{ __('calagopus::server.no_address') }}</span>
                    @endif
                </div>
                @if ($summary['location'])
                    <div class="flex flex-wrap items-center gap-x-2 text-base">
                        <span>{{ __('calagopus::server.location') }}:</span>
                        <span class="text-base/50 break-words">{{ $summary['location'] }}</span>
                    </div>
                @endif
                @if ($summary['uptime'])
                    <div class="flex flex-wrap items-center gap-x-2 text-base">
                        <span>{{ __('calagopus::server.uptime') }}:</span>
                        <span class="text-base/50">{{ $summary['uptime'] }}</span>
                    </div>
                @endif
                <div class="text-base">
                    <span class="mr-2">{{ __('calagopus::server.includes') }}:</span>
                    <span class="text-base/50">
                        @foreach ($summary['includes'] as $include)
                            <span class="whitespace-nowrap">{{ $include['label'] }} <span class="font-semibold text-base">{{ $include['value'] }}</span></span>@if (!$loop->last) &middot; @endif
                        @endforeach
                    </span>
                </div>
            </div>

            <div class="overflow-hidden">
                @foreach (['memory', 'disk', 'cpu'] as $key)
                    @php($label = __('calagopus::server.' . $key))
                    @php($resource = $summary['resources'][$key])
                    <div class="mb-2">
                        <div class="flex flex-wrap items-center justify-between gap-x-2 text-base">
                            <span>{{ $label }}:</span>
                            <span class="text-base/50">
                                @if ($resource['used'] !== null){{ $resource['used'] }} / @endif{{ $resource['limit'] }}
                            </span>
                        </div>
                        @if ($resource['percent'] !== null)
                            <div class="w-full h-2 mt-1 rounded-full bg-neutral overflow-hidden" role="progressbar"
                                aria-label="{{ $label }}" aria-valuenow="{{ $resource['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-2 rounded-full {{ $resource['percent'] >= 90 ? 'bg-red-500' : 'bg-primary' }}"
                                    style="width: {{ $resource['percent'] }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
