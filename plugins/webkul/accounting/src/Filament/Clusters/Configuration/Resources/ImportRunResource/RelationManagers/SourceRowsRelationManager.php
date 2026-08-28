<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SourceRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceRows';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_row_number')->label('Source row')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('messages')->formatStateUsing(function ($state): string {
                    return collect($state ?? [])->pluck('message')->implode('; ');
                })->wrap(),
                TextColumn::make('transformed_values')->label('Mapped values')->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->wrap()->toggleable(),
                TextColumn::make('canonical_type')->label('ERP record')->formatStateUsing(fn ($state, $record): string => $state ? class_basename($state).' #'.$record->canonical_id : 'Not imported'),
                TextColumn::make('processed_at')->dateTime()->placeholder('Not processed'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pass' => 'Pass', 'warning' => 'Warning', 'error' => 'Error', 'duplicate' => 'Duplicate',
                ]),
            ])
            ->defaultSort('source_row_number');
    }
}
