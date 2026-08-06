<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'sort'];

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'category_id');
    }
}
