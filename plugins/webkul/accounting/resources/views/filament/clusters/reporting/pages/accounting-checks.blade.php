<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}
        @php $completeness = $this->completeness; @endphp
        <x-filament::section>
            <x-slot name="heading">Report completeness: {{ $completeness['status']->value }}</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Bank statements: {{ $completeness['statement_count'] }} · Awaiting review: {{ $completeness['awaiting_review_count'] }} ·
                Opening balances: {{ $completeness['has_opening_balances'] ? 'Yes' : 'No' }} · Posted non-bank adjustments: {{ $completeness['manual_adjustment_count'] }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Accounting Reconciliation Checks</x-slot>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr><th class="px-3 py-2 text-left">Check</th><th class="px-3 py-2 text-right">Actual</th><th class="px-3 py-2 text-right">Expected</th><th class="px-3 py-2 text-right">Difference</th><th class="px-3 py-2 text-right">Tolerance</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Where to fix</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($this->checkRows as $check)
                            <tr>
                                <td class="px-3 py-2">{{ $check['name'] }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($check['actual'], 2) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($check['expected'], 2) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($check['difference'], 2) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($check['tolerance'], 2) }}</td>
                                <td class="px-3 py-2 font-semibold {{ $check['status'] === 'PASS' ? 'text-success-600' : 'text-danger-600' }}">{{ $check['status'] }}</td>
                                <td class="px-3 py-2"><x-filament::link :href="$check['fix_url']">{{ $check['where_to_fix'] }}</x-filament::link></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
