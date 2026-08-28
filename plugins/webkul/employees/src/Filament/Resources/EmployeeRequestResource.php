<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Support\Enums\NavigationGroup;

class EmployeeRequestResource extends Resource
{
    protected static ?string $model = EmployeeRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 13;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Employee Requests';
    }

    public static function form(Schema $schema): Schema
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visibleEmployeeIds = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        return $schema->components([
            Hidden::make('company_id')->default($companyId),
            Hidden::make('requested_by')->default(fn (): ?int => Auth::id()),
            Hidden::make('status')->default('draft'),
            Select::make('employee_id')
                ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->whereIn('id', $visibleEmployeeIds))
                ->default(fn (): ?int => Auth::user()?->employee?->id)
                ->required()->searchable()->preload(),
            Select::make('request_type_id')
                ->relationship('requestType', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->where('is_active', true))
                ->required()->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->columnSpanFull(),
            TextInput::make('amount')->numeric()->minValue(0),
            Select::make('currency_id')
                ->relationship('currency', 'name')
                ->default(fn (): ?int => Auth::user()?->defaultCompany?->currency_id)
                ->searchable()->preload(),
            FileUpload::make('attachments')
                ->multiple()->directory('employees/requests')->visibility('private')->columnSpanFull(),
            KeyValue::make('payload')->label('Additional request details')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->placeholder('Draft'),
            TextColumn::make('employee.name')->searchable()->sortable(),
            TextColumn::make('requestType.name')->label('Request type')->searchable(),
            TextColumn::make('title')->searchable()->limit(40),
            TextColumn::make('amount')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', 'pending_approval' => 'warning', default => 'gray',
            }),
            TextColumn::make('accountingMove.name')->label('Accounting draft')->placeholder('—'),
            TextColumn::make('submitted_at')->dateTime()->placeholder('Not submitted'),
        ])->filters([
            SelectFilter::make('status')->options([
                'draft'    => 'Draft', 'pending_approval' => 'Pending approval',
                'approved' => 'Approved', 'rejected' => 'Rejected',
            ]),
        ])->recordActions([
            Action::make('submit')
                ->icon('heroicon-o-paper-airplane')->color('primary')->requiresConfirmation()
                ->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true))
                ->action(function (EmployeeRequest $record): void {
                    app(EmployeeRequestService::class)->submit($record, Auth::user());
                    Notification::make()->success()->title('Employee request submitted for approval')->send();
                }),
            Action::make('refresh_approval')
                ->label('Refresh approval')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (EmployeeRequest $record): bool => $record->approval_request_id !== null && $record->status === 'pending_approval')
                ->action(function (EmployeeRequest $record): void {
                    app(EmployeeRequestService::class)->synchronize($record);
                    Notification::make()->success()->title('Approval status refreshed')->send();
                }),
            EditAction::make()->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true)),
            DeleteAction::make()->visible(fn (EmployeeRequest $record): bool => $record->status === 'draft'),
        ])->headerActions([CreateAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        return parent::getEloquentQuery()->where('company_id', $companyId)->whereIn('employee_id', $visible);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeRequests::route('/')];
    }
}
