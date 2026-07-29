<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CardImport;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardImportPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CardImport');
    }

    public function view(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('View:CardImport');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CardImport');
    }

    public function update(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('Update:CardImport');
    }

    public function delete(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('Delete:CardImport');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CardImport');
    }

    public function restore(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('Restore:CardImport');
    }

    public function forceDelete(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('ForceDelete:CardImport');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CardImport');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CardImport');
    }

    public function replicate(AuthUser $authUser, CardImport $cardImport): bool
    {
        return $authUser->can('Replicate:CardImport');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CardImport');
    }

}