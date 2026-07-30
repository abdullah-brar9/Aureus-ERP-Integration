<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages\CreateImportProfile;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages\EditImportProfile;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages\ListImportProfiles;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Services\Import\ImportEntityRegistry;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Accounting\Services\Import\ImportProfileDefinitionService;
use Webkul\Accounting\Support\AccountingPermissions;

class ImportProfileResource extends Resource
{
    protected static ?string $model = ImportProfile::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return 'Import Profiles';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return $schema->components([
            Section::make('Profile')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('entity_type')->options(app(ImportEntityRegistry::class)->entityOptions())->required()->searchable()->live(),
                Select::make('file_type')->options(['csv' => 'CSV', 'xlsx' => 'Excel workbook (.xlsx)', 'xls' => 'Excel workbook (.xls)'])->required()->default('xlsx'),
                TextInput::make('sheet_name')->label('Sheet name')->helperText('Leave blank to use the first sheet.'),
                TextInput::make('header_row')->numeric()->minValue(1)->default(1)->required(),
                TextInput::make('data_start_row')->numeric()->minValue(1)->default(2)->required(),
                TextInput::make('skip_rows')->numeric()->minValue(0)->default(0)->required(),
                Select::make('blank_row_rule')->options(['skip' => 'Skip blank rows', 'stop' => 'Stop at first blank row'])->default('skip')->required(),
                TextInput::make('delimiter')->default(',')->maxLength(5)->visible(fn ($get): bool => $get('file_type') === 'csv'),
                TextInput::make('encoding')->default('UTF-8')->visible(fn ($get): bool => $get('file_type') === 'csv'),
                TextInput::make('version')->numeric()->default(1)->disabled()->dehydrated(),
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated(),
                Toggle::make('is_active')->label('Active version')->default(false),
            ]),
            Section::make('Column mapping')->description('Map a source header, alias, or one-based column position to a canonical ERP field.')->schema([
                Repeater::make('mappings')
                    ->relationship()
                    ->orderColumn('position')
                    ->reorderable()
                    ->columns(3)
                    ->schema([
                        TextInput::make('source_header')->label('Source header'),
                        TextInput::make('source_position')->label('Column position')->numeric()->minValue(1),
                        TagsInput::make('source_aliases')->label('Header aliases'),
                        Select::make('target_field')->options(app(ImportEntityRegistry::class)->allFields())->searchable()->required(),
                        Toggle::make('is_required')->label('Required in this profile'),
                        TagsInput::make('validation_rules')->helperText('Supported checks: email, date, numeric, boolean.'),
                        Repeater::make('transformations')->columns(3)->schema([
                            Select::make('type')->options([
                                'trim'          => 'Trim spaces', 'upper' => 'Uppercase', 'lower' => 'Lowercase', 'title' => 'Title case',
                                'date'          => 'Convert date', 'decimal' => 'Convert number', 'boolean' => 'Convert yes/no',
                                'null_if'       => 'Convert value to blank', 'default' => 'Default when blank', 'map' => 'Map values',
                                'concat'        => 'Join fields', 'split' => 'Split value', 'find_replace' => 'Find and replace',
                                'regex_replace' => 'Safe pattern replace',
                            ])->required(),
                            TextInput::make('value')->label('Default/value'),
                            TextInput::make('format')->label('Input date format'),
                            TextInput::make('output')->label('Output date format'),
                            TextInput::make('decimal_separator'),
                            TextInput::make('thousands_separator'),
                            TextInput::make('scale')->numeric(),
                            TextInput::make('find'),
                            TextInput::make('replace'),
                            TextInput::make('pattern'),
                            TextInput::make('replacement'),
                            TextInput::make('separator'),
                            TextInput::make('index')->numeric(),
                        ])->collapsible()->collapsed(),
                    ])
                    ->minItems(1)
                    ->defaultItems(1),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('entity_type')->badge()->sortable(),
                TextColumn::make('file_type')->label('File')->badge(),
                TextColumn::make('version')->sortable(),
                TextColumn::make('mappings_count')->counts('mappings')->label('Mappings'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('owner.name')->label('Owner')->placeholder('System'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('previewImport')
                    ->label('Preview import')
                    ->icon('heroicon-o-magnifying-glass')
                    ->authorize(AccountingPermissions::RunConfiguredImports)
                    ->visible(fn (ImportProfile $record): bool => $record->is_active)
                    ->schema([
                        FileUpload::make('file')->disk('local')->directory('accounting/import-staging')->acceptedFileTypes([
                            'text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])->maxSize(20480)->required()->storeFileNamesIn('original_name'),
                    ])
                    ->action(function (ImportProfile $record, array $data): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $run = app(ImportPreviewService::class)->preview($record, $path, $data['original_name'] ?? basename($path), Auth::id());
                        Notification::make()->success()->title("Preview {$run->reference} created")
                            ->body("{$run->passed_rows} passed, {$run->warning_rows} warnings, {$run->failed_rows} errors.")->send();
                    }),
                Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->authorize(AccountingPermissions::ManageImportProfiles)
                    ->visible(fn (ImportProfile $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (ImportProfile $record): void {
                        DB::transaction(function () use ($record): void {
                            ImportProfile::query()->where('company_id', $record->company_id)->where('name', $record->name)->update(['is_active' => false, 'activated_at' => null]);
                            $record->update(['is_active' => true, 'activated_at' => now()]);
                        });
                    }),
                Action::make('newVersion')
                    ->label('New version')
                    ->icon('heroicon-o-document-duplicate')
                    ->authorize(AccountingPermissions::ManageImportProfiles)
                    ->action(function (ImportProfile $record): void {
                        DB::transaction(function () use ($record): void {
                            $copy = $record->replicate(['is_active', 'activated_at']);
                            $copy->version = ImportProfile::query()->where('company_id', $record->company_id)->where('name', $record->name)->max('version') + 1;
                            $copy->supersedes_profile_id = $record->id;
                            $copy->owner_id = Auth::id();
                            $copy->is_active = false;
                            $copy->save();
                            foreach ($record->mappings as $mapping) {
                                $copy->mappings()->create($mapping->only(['position', 'source_header', 'source_position', 'source_aliases', 'target_field', 'transformations', 'validation_rules', 'is_required']));
                            }
                        });
                        Notification::make()->success()->title('A new inactive profile version was created.')->send();
                    }),
                Action::make('exportDefinition')
                    ->label('Export profile')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(AccountingPermissions::ManageImportProfiles)
                    ->action(function (ImportProfile $record) {
                        $definition = app(ImportProfileDefinitionService::class)->export($record);

                        return response()->streamDownload(
                            fn () => print json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                            Str::slug($record->name)."-v{$record->version}.json",
                            ['Content-Type' => 'application/json'],
                        );
                    }),
                EditAction::make()->authorize(AccountingPermissions::ManageImportProfiles)->visible(fn (ImportProfile $record): bool => ! $record->is_active),
                DeleteAction::make()->authorize(AccountingPermissions::ManageImportProfiles)->visible(fn (ImportProfile $record): bool => ! $record->is_active && ! $record->runs()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListImportProfiles::route('/'),
            'create' => CreateImportProfile::route('/create'),
            'edit'   => EditImportProfile::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageImportProfiles) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageImportProfiles) ?? false;
    }
}
