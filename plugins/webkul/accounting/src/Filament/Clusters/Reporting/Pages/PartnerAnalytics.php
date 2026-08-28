<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Services\PartnerAnalyticsService;

class PartnerAnalytics extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.partner-analytics';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 99;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_partner_analytics';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Partner Reports';
    }

    public static function getNavigationLabel(): string
    {
        return 'Customer & Vendor Analytics';
    }

    public function mount(): void
    {
        $this->form->fill([
            'party_type' => 'customer',
            'date_from'  => now()->startOfYear()->toDateString(),
            'date_to'    => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Analytics filters')->columns(3)->schema([
                Select::make('party_type')->options(['customer' => 'Customers', 'vendor' => 'Vendors'])->required()->live(),
                DatePicker::make('date_from')->required()->native(false)->live(),
                DatePicker::make('date_to')->required()->native(false)->afterOrEqual('date_from')->live(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function analytics(): array
    {
        $state = $this->form->getState();

        return app(PartnerAnalyticsService::class)->summary(
            (int) Auth::user()?->default_company_id,
            (string) ($state['party_type'] ?? 'customer'),
            (string) ($state['date_from'] ?? now()->startOfYear()->toDateString()),
            (string) ($state['date_to'] ?? now()->toDateString()),
        );
    }
}
