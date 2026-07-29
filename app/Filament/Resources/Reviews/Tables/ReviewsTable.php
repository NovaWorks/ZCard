<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Review;
use App\Support\ReviewService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.name')->label('商品')->limit(20),
                TextColumn::make('user.username')->label('用户')->limit(15),
                TextColumn::make('rating')->label('评分')->badge()
                    ->formatStateUsing(fn (int $state): string => $state . ' ★'),
                TextColumn::make('content')->label('内容')->limit(40),
                TextColumn::make('status')->label('状态')->badge()->colors([
                    'warning' => Review::STATUS_PENDING,
                    'success' => Review::STATUS_APPROVED,
                    'danger' => Review::STATUS_REJECTED,
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    Review::STATUS_PENDING => '待审核',
                    Review::STATUS_APPROVED => '已通过',
                    Review::STATUS_REJECTED => '已拒绝',
                    default => $state,
                }),
                TextColumn::make('created_at')->dateTime()->label('时间')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        Review::STATUS_PENDING => '待审核',
                        Review::STATUS_APPROVED => '已通过',
                        Review::STATUS_REJECTED => '已拒绝',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('通过')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Review $record) => $record->status === Review::STATUS_PENDING)
                    ->action(function (Review $record, ReviewService $service) {
                        $service->approveReview($record->id);
                        Notification::make()->success()->title('已通过审核')->send();
                    }),
                Action::make('reject')
                    ->label('拒绝')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Review $record) => $record->status === Review::STATUS_PENDING)
                    ->action(function (Review $record, ReviewService $service) {
                        $service->rejectReview($record->id);
                        Notification::make()->success()->title('已拒绝该评价')->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
