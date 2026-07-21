<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($this->reviewData as $entry)
            <x-filament::section collapsible :collapsed="$entry['unmapped']->isEmpty() && $entry['formulas']->isEmpty() && $entry['other']->isEmpty()">
                <x-slot name="heading">
                    {{ $entry['template']->name }}
                </x-slot>

                <x-slot name="description">
                    {{ __('accounting::filament/clusters/reporting/pages/report-mapping-review.content.summary', [
                        'unmapped' => $entry['unmapped']->count(),
                        'formulas' => $entry['formulas']->count(),
                        'other'    => $entry['other']->count(),
                    ]) }}
                </x-slot>

                @if ($entry['unmapped']->isEmpty() && $entry['formulas']->isEmpty() && $entry['other']->isEmpty())
                    <p class="text-sm text-success-600">
                        {{ __('accounting::filament/clusters/reporting/pages/report-mapping-review.content.clean') }}
                    </p>
                @else
                    <div class="space-y-4 text-sm">
                        @foreach ([
                            'unmapped' => __('accounting::filament/clusters/reporting/pages/report-mapping-review.content.unmapped'),
                            'formulas' => __('accounting::filament/clusters/reporting/pages/report-mapping-review.content.formulas'),
                            'other'    => __('accounting::filament/clusters/reporting/pages/report-mapping-review.content.other'),
                        ] as $key => $label)
                            @if ($entry[$key]->isNotEmpty())
                                <div>
                                    <h4 class="mb-1 font-semibold text-gray-700 dark:text-gray-200">{{ $label }}</h4>
                                    <ul class="list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                        @foreach ($entry[$key] as $issue)
                                            <li>
                                                <span @class(['font-semibold', 'text-danger-600' => $issue->isError(), 'text-warning-600' => ! $issue->isError()])>
                                                    {{ strtoupper($issue->severity) }}
                                                </span>
                                                {{ $issue->message }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
