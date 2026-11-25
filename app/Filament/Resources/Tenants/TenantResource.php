<?php

namespace App\Filament\Resources\Tenants;

use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: staff see accounts and billing state. Plans change through Stripe only. */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('plan')->badge()->color(fn (string $state) => $state === 'pro' ? 'success' : 'gray'),
                TextColumn::make('subscription_status')->badge()->placeholder('none'),
                TextColumn::make('current_period_end')->label('Renews')->date()->placeholder('-'),
                TextColumn::make('users_count')->counts('users')->label('Users')->sortable(),
                TextColumn::make('stripe_customer_id')->label('Stripe customer')->copyable()->placeholder('-'),
                TextColumn::make('created_at')->label('Signed up')->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
        ];
    }
}
