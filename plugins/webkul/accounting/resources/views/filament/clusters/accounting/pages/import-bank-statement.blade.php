<x-filament-panels::page>
    {{ $this->form }}

    @if ($preview)
        <x-filament::section heading="Conversion preview">
            <p>
                {{ $preview['bank'] }} · {{ $preview['bank_account_number'] }} · {{ $preview['period'] }} ·
                detected {{ $preview['detected_currency'] }} · selected {{ $preview['selected_currency'] }} ·
                {{ $preview['row_count'] }} transactions{{ $preview['truncated'] ? ' (first 1,000 shown)' : '' }}
            </p>
            @foreach ($preview['validation_errors'] as $error)
                <p>{{ $error['message'] }}</p>
            @endforeach
            @foreach ($preview['missing_rates'] as $missingRate)
                <p>{{ $missingRate }}</p>
            @endforeach
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Original</th><th>Rate</th><th>Company amount</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td><td>{{ $row['description'] }}</td>
                            <td>{{ $row['original_amount'] }} {{ $row['original_currency'] }}</td>
                            <td>{{ $row['exchange_rate'] }}</td>
                            <td>{{ $row['company_amount'] }} {{ $row['company_currency'] }}</td>
                            <td>{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament::section class="mt-6">
        <x-slot name="heading">Posting safety</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Import only creates normalized statement rows and draft mappings. Reconciliation failures, duplicate files,
            unapproved mappings and unbalanced journals cannot post to the official ledger.
        </p>
    </x-filament::section>
</x-filament-panels::page>
