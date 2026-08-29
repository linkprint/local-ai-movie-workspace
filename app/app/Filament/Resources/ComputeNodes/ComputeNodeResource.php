<?php

namespace App\Filament\Resources\ComputeNodes;

use App\Filament\Resources\ComputeNodes\Pages\CreateComputeNode;
use App\Filament\Resources\ComputeNodes\Pages\EditComputeNode;
use App\Filament\Resources\ComputeNodes\Pages\ListComputeNodes;
use App\Filament\Resources\ComputeNodes\Schemas\ComputeNodeForm;
use App\Filament\Resources\ComputeNodes\Tables\ComputeNodesTable;
use App\Models\ComputeNode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ComputeNodeResource extends Resource
{
    protected static ?string $model = ComputeNode::class;

    protected static ?string $slug = 'ai-servers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function getModelLabel(): string
    {
        return __('ui.compute_nodes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.compute_nodes.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ComputeNodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComputeNodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComputeNodes::route('/'),
            'create' => CreateComputeNode::route('/create'),
            'edit' => EditComputeNode::route('/{record}/edit'),
        ];
    }
}
