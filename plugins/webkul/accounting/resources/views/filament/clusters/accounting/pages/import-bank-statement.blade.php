<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section class="mt-6">
        <x-slot name="heading">Posting safety</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Import only creates normalized statement rows and draft mappings. Reconciliation failures, duplicate files,
            unapproved mappings and unbalanced journals cannot post to the official ledger.
        </p>
    </x-filament::section>
</x-filament-panels::page>
