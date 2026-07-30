<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Recent revaluations">
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Currency</th>
                    <th>Rate</th>
                    <th>Difference</th>
                    <th>Status</th>
                    <th>Journal / reversal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($revaluations as $revaluation)
                    <tr>
                        <td>{{ $revaluation->period_end?->format('Y-m-d') }}</td>
                        <td>{{ $revaluation->currency?->code }}</td>
                        <td>{{ $revaluation->exchangeRate?->rate }}</td>
                        <td>{{ $revaluation->difference }}</td>
                        <td>{{ $revaluation->status }}</td>
                        <td>{{ $revaluation->move?->name }} / {{ $revaluation->reversalMove?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No revaluations have been created.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
