<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Models\CoaImportBatch;

class ImportedChartOfAccounts extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.configuration.pages.imported-chart-of-accounts';

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_imported_chart_of_accounts';
    }

    public static function getNavigationLabel(): string
    {
        return 'Imported Chart of Accounts';
    }

    public function getTitle(): string
    {
        return 'Imported Chart of Accounts';
    }

    public function mount(): void
    {
        $latestBatchId = $this->batchQuery()->latest('id')->value('id');

        $this->form->fill(['batch_id' => $latestBatchId]);
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Source import')
                ->schema([
                    Select::make('batch_id')
                        ->label('Import batch')
                        ->options(fn () => $this->batchQuery()
                            ->latest('id')
                            ->get()
                            ->mapWithKeys(fn (CoaImportBatch $batch) => [
                                $batch->id => "#{$batch->id} · {$batch->filename} · {$batch->created_at?->format('Y-m-d H:i')}",
                            ]))
                        ->searchable()
                        ->live(),
                ]),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    #[Computed]
    public function selectedBatch(): ?CoaImportBatch
    {
        $batchId = (int) ($this->data['batch_id'] ?? 0);

        if ($batchId === 0) {
            return null;
        }

        return $this->batchQuery()
            ->with(['sourceRows.canonicalAccount'])
            ->find($batchId);
    }

    protected function batchQuery()
    {
        return CoaImportBatch::query()
            ->where('company_id', Auth::user()?->default_company_id);
    }
}
