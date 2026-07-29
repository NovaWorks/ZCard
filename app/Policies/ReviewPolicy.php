<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Review;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReviewPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Review');
    }

    public function view(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('View:Review');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Review');
    }

    public function update(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('Update:Review');
    }

    public function delete(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('Delete:Review');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Review');
    }

    public function restore(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('Restore:Review');
    }

    public function forceDelete(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('ForceDelete:Review');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Review');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Review');
    }

    public function replicate(AuthUser $authUser, Review $review): bool
    {
        return $authUser->can('Replicate:Review');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Review');
    }

}