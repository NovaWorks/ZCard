<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'merchant_id', 'name', 'slug', 'sort', 'status'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** 树形列表展示用的缩进名 */
    public function getIndentedNameAttribute(): string
    {
        $depth = 0;
        $p = $this->parent;
        while ($p) {
            $depth++;
            $p = $p->parent;
        }
        return str_repeat('— ', $depth) . $this->name;
    }
}
