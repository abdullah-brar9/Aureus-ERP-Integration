<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @php $batch = $this->selectedBatch; @endphp

        @if ($batch)
            <x-filament::section>
                <x-slot name="heading">{{ $batch->filename ?: 'Imported file' }}</x-slot>

                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-gray-500">Company</dt><dd>{{ $batch->company?->name }}</dd></div>
                    <div><dt class="text-gray-500">Sheet</dt><dd>{{ $batch->source_sheet ?: 'CSV' }}</dd></div>
                    <div><dt class="text-gray-500">Header row</dt><dd>{{ $batch->header_row_number ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">SHA-256</dt><dd class="break-all font-mono text-xs">{{ $batch->file_hash ?: '—' }}</dd></div>
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Lossless source view</x-slot>

                <div class="overflow-x-auto rounded border border-gray-200 dark:border-white/5">
                    <table class="min-w-full whitespace-nowrap text-sm">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-2 text-left">Source row</th>
                                @foreach (($batch->original_headers ?? []) as $header)
                                    <th class="px-3 py-2 text-left">{{ $header }}</th>
                                @endforeach
                                <th class="px-3 py-2 text-left">Canonical account</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach (($batch->metadata_rows ?? []) as $metadataIndex => $metadataRow)
                                <tr class="bg-gray-50/60 italic dark:bg-white/5">
                                    <td class="px-3 py-2">{{ $metadataIndex + 1 }}</td>
                                    @foreach (($batch->original_headers ?? []) as $index => $header)
                                        <td class="px-3 py-2">{{ $metadataRow[$index] ?? '' }}</td>
                                    @endforeach
                                    <td class="px-3 py-2 text-gray-500">Metadata</td>
                                </tr>
                            @endforeach
                            @foreach ($batch->sourceRows as $sourceRow)
                                <tr>
                                    <td class="px-3 py-2">{{ $sourceRow->source_row_number }}</td>
                                    @foreach (($batch->original_headers ?? []) as $index => $header)
                                        <td class="px-3 py-2">{{ $sourceRow->raw_row[$index] ?? '' }}</td>
                                    @endforeach
                                    <td class="px-3 py-2">
                                        @if ($sourceRow->canonicalAccount)
                                            <x-filament::link :href="\Webkul\Accounting\Filament\Clusters\Reporting\Pages\GeneralLedger::getUrl(['account_id' => $sourceRow->canonical_account_id])">
                                                {{ $sourceRow->canonicalAccount->code }} {{ $sourceRow->canonicalAccount->name }}
                                            </x-filament::link>
                                        @else
                                            <span class="text-danger-600">Not linked</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>No imported Chart of Accounts batch is available for this company.</x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
