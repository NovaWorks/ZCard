<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'username', 'name', 'email', 'password', 'status',
    'balance', 'total_recharge', 'total_consumption', 'password_changed_at', 'last_login_at',
    'phone', 'qq', 'avatar', 'points', 'pid', 'group_id', 'login_ip',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * 仅超级管理员可进入全局后台；分站主使用独立自助控制台。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (int) $this->status === 1 && $this->hasRole('super_admin');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function userGroup()
    {
        return $this->belongsTo(UserGroup::class, 'group_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'pid');
    }
}
