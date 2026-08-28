<?php

namespace Webkul\Security\Filament\Resources;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Filament\Resources\TeamResource\Pages\ManageTeams;
use Webkul\Security\Models\Team;
use Webkul\Security\Traits\HasResourcePermissionQuery;
use Webkul\Support\Enums\NavigationGroup;

class TeamResource extends Resource
{
    use HasResourcePermissionQuery;

    protected static ?string $model = Team::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('security::filament/resources/team.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Setting;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('security::filament/resources/team.form.fields.name'))
                    ->required()
                    ->maxLength(255),
                Select::make('company_id')
                    ->relationship('company', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn('id', Auth::user()?->allowedCompanies()->pluck('companies.id') ?? []))
                    ->default(fn (): ?int => Auth::user()?->default_company_id)
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('department_id')
                    ->relationship('department', 'complete_name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))
                    ->searchable()
                    ->preload(),
                Select::make('manager_employee_id')
                    ->label('Team manager')
                    ->relationship('manager', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id)->where('is_active', true))
                    ->searchable()
                    ->preload(),
                Textarea::make('description')->columnSpanFull(),
                Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('security::filament/resources/team.table.columns.name'))
                    ->searchable()
                    ->limit(50)
                    ->sortable(),
                TextColumn::make('company.name')->label('Company')->sortable(),
                TextColumn::make('department.complete_name')->label('Department')->placeholder('—')->sortable(),
                TextColumn::make('manager.name')->label('Manager')->placeholder('—')->searchable(),
                TextColumn::make('creator.name')
                    ->label(__('security::filament/resources/team.table.columns.created-by'))
                    ->searchable()
                    ->limit(50)
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('security::filament/resources/team.table.actions.edit.notification.title'))
                            ->body(__('security::filament/resources/team.table.actions.edit.notification.body'))
                    ),
                DeleteAction::make()
                    ->hidden(fn (Team $record): bool => $record->users?->isNotEmpty())
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('security::filament/resources/team.table.actions.delete.notification.title'))
                            ->body(__('security::filament/resources/team.table.actions.delete.notification.body'))
                    ),
            ])
            ->emptyStateActions([
                CreateAction::make()
                    ->icon('heroicon-o-plus-circle')
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('security::filament/resources/team.table.empty-state-actions.create.notification.title'))
                            ->body(__('security::filament/resources/team.table.empty-state-actions.create.notification.body'))
                    ),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->icon('heroicon-o-user')
                    ->placeholder('—')
                    ->label(__('security::filament/resources/team.infolist.entries.name')),
                TextEntry::make('company.name')->label('Company'),
                TextEntry::make('department.complete_name')->label('Department')->placeholder('—'),
                TextEntry::make('manager.name')->label('Team manager')->placeholder('—'),
                TextEntry::make('description')->placeholder('—'),
                IconEntry::make('is_active')->boolean(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $companyId = Auth::user()?->default_company_id;

        return parent::getEloquentQuery()
            ->where(fn (Builder $query): Builder => $query->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTeams::route('/'),
        ];
    }
}
