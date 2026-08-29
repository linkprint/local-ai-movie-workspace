<?php

namespace App\Filament\Resources\AuditEvents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('actor_id')
                    ->label(__('ui.admin.fields.actor_id'))
                    ->placeholder('-'),
                TextEntry::make('action')->label(__('ui.admin.fields.action')),
                TextEntry::make('target_type')
                    ->label(__('ui.admin.fields.target_type'))
                    ->placeholder('-'),
                TextEntry::make('target_id')
                    ->label(__('ui.admin.fields.target_id'))
                    ->placeholder('-'),
                TextEntry::make('ip_address')
                    ->label(__('ui.admin.fields.ip_address'))
                    ->placeholder('-'),
                TextEntry::make('request_id')
                    ->label(__('ui.admin.fields.request_id'))
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label(__('ui.admin.fields.created_at'))
                    ->dateTime(),
            ]);
    }
}
