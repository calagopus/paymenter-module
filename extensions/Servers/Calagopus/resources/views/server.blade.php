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

<div class="bg-background-secondary border border-neutral p-6 rounded-lg">
    @if ($error)
        <p class="text-base/50">Unable to load server information from the panel. Please try again later.</p>
    @elseif (!$summary)
        <p class="text-base/50">Your server is still being set up. Check back shortly.</p>
    @else
        <div class="flex flex-col md:flex-row justify-between gap-2">
            <div>
                <h4 class="text-lg font-semibold">{{ $summary['name'] }}</h4>
                @if ($summary['egg'])
                    <span class="text-sm text-base/50">{{ $summary['egg'] }}@if ($summary['nest']) &middot; {{ $summary['nest'] }}@endif</span>
                @endif
            </div>
            @if ($summary['state'])
                <span class="font-semibold {{ $tones[$summary['state']['tone']] }}">{{ $summary['state']['label'] }}</span>
            @endif
        </div>

        <div class="grid md:grid-cols-2 gap-4 mt-4">
            <div>
                <div class="flex items-center text-base">
                    <span class="mr-2">Address:</span>
                    @if ($summary['address'])
                        <span class="font-mono text-base/50">{{ $summary['address'] }}</span>
                        <button type="button" class="ml-2 text-sm font-semibold text-primary hover:underline cursor-pointer"
                            x-data="{ copied: false }" data-address="{{ $summary['address'] }}"
                            @click="navigator.clipboard.writeText($el.dataset.address); copied = true; setTimeout(() => copied = false, 2000)"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                    @else
                        <span class="text-base/50">No address assigned</span>
                    @endif
                </div>
                @if ($summary['location'])
                    <div class="flex items-center text-base">
                        <span class="mr-2">Location:</span>
                        <span class="text-base/50">{{ $summary['location'] }}</span>
                    </div>
                @endif
                @if ($summary['uptime'])
                    <div class="flex items-center text-base">
                        <span class="mr-2">Uptime:</span>
                        <span class="text-base/50">{{ $summary['uptime'] }}</span>
                    </div>
                @endif
                <div class="text-base">
                    <span class="mr-2">Includes:</span>
                    <span class="text-base/50">
                        @foreach ($summary['includes'] as $include)
                            <span class="whitespace-nowrap">{{ $include['label'] }} <span class="font-semibold text-base">{{ $include['value'] }}</span></span>@if (!$loop->last) &middot; @endif
                        @endforeach
                    </span>
                </div>
            </div>

            <div>
                @foreach (['memory' => 'Memory', 'disk' => 'Disk', 'cpu' => 'CPU'] as $key => $label)
                    @php($resource = $summary['resources'][$key])
                    <div class="mb-2">
                        <div class="flex items-center justify-between text-base">
                            <span class="mr-2">{{ $label }}:</span>
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
