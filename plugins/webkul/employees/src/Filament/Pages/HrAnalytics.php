<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class HrAnalytics extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?int $navigationSort = 20;

    protected string $view = 'employees::filament.pages.hr-analytics';

    public string $fromDate;

    public string $toDate;

    public function mount(): void
    {
        $this->fromDate = now()->startOfYear()->toDateString();
        $this->toDate = now()->endOfYear()->toDateString();
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'HR Analytics';
    }

    public function getTitle(): string
    {
        return 'HR Analytics';
    }

    public static function getPagePermission(): ?string
    {
        return HrPermissions::ViewAnalytics;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'analytics' => app(HrAnalyticsService::class)->summary(
                (int) Auth::user()?->default_company_id,
                $this->fromDate,
                $this->toDate,
            ),
        ];
    }
}
