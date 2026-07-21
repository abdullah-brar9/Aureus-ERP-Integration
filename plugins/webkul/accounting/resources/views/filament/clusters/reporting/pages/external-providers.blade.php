<x-filament-panels::page>
    <div class="space-y-6">
        @php
            $data = $this->providersData;
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                {{ __('accounting::filament/clusters/reporting/pages/external-providers.content.registered') }}
            </x-slot>

            @if (empty($data['keys']))
                <p class="text-sm text-gray-500">
                    {{ __('accounting::filament/clusters/reporting/pages/external-providers.content.none-registered') }}
                </p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($data['keys'] as $key)
                        <x-filament::badge color="success">{{ $key }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('accounting::filament/clusters/reporting/pages/external-providers.content.lines') }}
            </x-slot>

            @if ($data['lines']->isEmpty())
                <p class="text-sm text-gray-500">
                    {{ __('accounting::filament/clusters/reporting/pages/external-providers.content.none-used') }}
                </p>
            @else
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/5">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-500">
                            <th class="px-4 py-2">{{ __('accounting::filament/clusters/reporting/pages/external-providers.content.template') }}</th>
                            <th class="px-4 py-2">{{ __('accounting::filament/clusters/reporting/pages/external-providers.content.line') }}</th>
                            <th class="px-4 py-2">{{ __('accounting::filament/clusters/reporting/pages/external-providers.content.provider') }}</th>
                            <th class="px-4 py-2">{{ __('accounting::filament/clusters/reporting/pages/external-providers.content.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($data['lines'] as $line)
                            <tr>
                                <td class="px-4 py-2">{{ $line['template'] }}</td>
                                <td class="px-4 py-2">{{ $line['caption'] }}</td>
                                <td class="px-4 py-2 font-mono">{{ $line['provider'] ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <x-filament::badge :color="$line['registered'] ? 'success' : 'danger'">
                                        {{ $line['registered']
                                            ? __('accounting::filament/clusters/reporting/pages/external-providers.content.ok')
                                            : __('accounting::filament/clusters/reporting/pages/external-providers.content.missing') }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
