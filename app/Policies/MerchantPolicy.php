<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Merchant;
use Illuminate\Auth\Access\HandlesAuthorization;

class MerchantPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Merchant');
    }

    public function view(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('View:Merchant');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Merchant');
    }

    public function update(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('Update:Merchant');
    }

    public function delete(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('Delete:Merchant');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Merchant');
    }

    public function restore(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('Restore:Merchant');
    }

    public function forceDelete(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('ForceDelete:Merchant');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Merchant');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Merchant');
    }

    public function replicate(AuthUser $authUser, Merchant $merchant): bool
    {
        return $authUser->can('Replicate:Merchant');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Merchant');
    }

}