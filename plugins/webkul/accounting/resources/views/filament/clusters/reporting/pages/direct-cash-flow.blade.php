<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}
        @php $cash = $this->cashFlowData; $completeness = $this->completeness; @endphp

        <x-filament::section compact>
            Currency mode: {{ $cash['currency_mode'] }} · status: {{ $cash['conversion_status'] }} · {{ $cash['rate_basis'] }}
            @foreach ($cash['warnings'] as $warning)
                <div class="text-warning-600">{{ $warning }}</div>
            @endforeach
        </x-filament::section>

        @foreach ($cash['reports'] as $currency => $report)
        <x-filament::section>
            <x-slot name="heading">Direct-method Cash Flow Statement — {{ $currency }}</x-slot>
            <x-slot name="description">Status: {{ $completeness['status']->value }} · {{ $completeness['provisional_label'] }}</x-slot>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr><th class="px-3 py-2 text-left">Category</th><th class="px-3 py-2 text-right">Amount</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($report['categories'] as $category => $amount)
                            <tr><td class="px-3 py-2">{{ $category }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($amount, 2) }}</td></tr>
                        @endforeach
                        <tr class="font-semibold"><td class="px-3 py-2">Net change in cash</td><td class="px-3 py-2 text-right">{{ number_format($report['net_change'], 2) }}</td></tr>
                        <tr><td class="px-3 py-2">Opening cash (posted ledger)</td><td class="px-3 py-2 text-right">{{ number_format($report['opening_cash'], 2) }}</td></tr>
                        <tr><td class="px-3 py-2">Statement opening reference</td><td class="px-3 py-2 text-right">{{ number_format($report['statement_opening_cash'], 2) }}</td></tr>
                        <tr class="font-semibold"><td class="px-3 py-2">Ending cash</td><td class="px-3 py-2 text-right">{{ number_format($report['ending_cash'], 2) }}</td></tr>
                        <tr><td class="px-3 py-2">Posted bank ledger cash</td><td class="px-3 py-2 text-right">{{ number_format($report['ledger_cash'], 2) }}</td></tr>
                        <tr class="font-semibold"><td class="px-3 py-2">Cash flow check</td><td class="px-3 py-2 text-right">{{ number_format($report['difference'], 2) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
