<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\Pages\ListImportRuns;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\Pages\ViewImportRun;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\RelationManagers\SourceRowsRelationManager;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Support\AccountingPermissions;

class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'Import Runs';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('profile.name')->label('Profile')->searchable(),
                TextColumn::make('profile.entity_type')->label('Entity')->badge(),
                TextColumn::make('profile_version')->label('Version'),
                TextColumn::make('original_filename')->label('File')->limit(35),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('passed_rows')->label('Pass')->numeric()->color('success'),
                TextColumn::make('warning_rows')->label('Warnings')->numeric()->color('warning'),
                TextColumn::make('failed_rows')->label('Errors')->numeric()->color('danger'),
                TextColumn::make('imported_rows')->label('Imported')->numeric(),
                TextColumn::make('importedBy.name')->label('User')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'previewed' => 'Previewed', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label('Confirm import')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(AccountingPermissions::RunConfiguredImports)
                    ->visible(fn (ImportRun $record): bool => $record->status === 'previewed' && $record->failed_rows === 0)
                    ->requiresConfirmation()
                    ->modalDescription('This creates canonical ERP records for every passing row. It never posts accounting documents automatically.')
                    ->action(function (ImportRun $record): void {
                        $completed = app(ImportExecutionService::class)->confirm($record, Auth::id());
                        Notification::make()->success()->title("{$completed->imported_rows} rows imported")->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [SourceRowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view'  => ViewImportRun::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::RunConfiguredImports) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
