<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource;
use Webkul\Accounting\Services\Import\ImportProfileDefinitionService;
use Webkul\Support\Models\Company;

class ListImportProfiles extends ListRecords
{
    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importDefinition')->label('Import profile')->icon('heroicon-o-arrow-up-tray')->schema([
                FileUpload::make('definition')->disk('local')->directory('accounting/profile-definitions')->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(1024)->required(),
                TextInput::make('name')->label('Name override')->helperText('Optional. Imported profiles are always inactive.'),
            ])->action(function (array $data): void {
                $json = file_get_contents(Storage::disk('local')->path($data['definition']));
                $definition = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
                $profile = app(ImportProfileDefinitionService::class)->import(
                    Company::query()->findOrFail(auth()->user()?->default_company_id),
                    $definition,
                    auth()->id(),
                    $data['name'] ?? null,
                );
                Notification::make()->success()->title("Imported {$profile->name} v{$profile->version}")->send();
            }),
        ];
    }
}
