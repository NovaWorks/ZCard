<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Card;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Card');
    }

    public function view(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('View:Card');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Card');
    }

    public function update(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('Update:Card');
    }

    public function delete(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('Delete:Card');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Card');
    }

    public function restore(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('Restore:Card');
    }

    public function forceDelete(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('ForceDelete:Card');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Card');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Card');
    }

    public function replicate(AuthUser $authUser, Card $card): bool
    {
        return $authUser->can('Replicate:Card');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Card');
    }

}