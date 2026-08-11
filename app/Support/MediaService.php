<?php

namespace App\Support;

use App\Models\Media;
use App\Models\MediaCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * 素材管理服务（Media Library 业务真理源,spec 2026-08-06）。
 * 负责分类 CRUD、图片上传入库、查询分页、物理删除与分类迁移。
 */
class MediaService
{
    /** 上传目录前缀(按年月分目录) */
    private const DISK = 'public';

    /** 支持的文件类型 */
    private const ALLOWED_MIMES = ['jpeg', 'png', 'webp', 'gif'];

    /** 单文件大小上限:10MB */
    private const MAX_SIZE_KB = 10240;

    /** 分类名称长度上限 */
    private const CATEGORY_NAME_MAX = 30;

    // ===== 分类管理 =====

    /**
     * 分类列表(含每分类图片数量、未分类数量、总数)。
     */
    public function categories(): array
    {
        $categories = MediaCategory::orderBy('sort')->orderBy('id')->get()
            ->map(function (MediaCategory $category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sort' => $category->sort,
                    'media_count' => $category->media()->count(),
                    'created_at' => $category->created_at?->toDateTimeString(),
                ];
            })->values();

        $uncategorized = Media::whereNull('category_id')->count();
        $total = Media::count();

        return [
            'categories' => $categories,
            'uncategorized' => $uncategorized,
            'total' => $total,
        ];
    }

    /**
     * 新增分类(重名校验 + 长度校验)。
     */
    public function createCategory(string $name): MediaCategory
    {
        $name = trim($name);
        $this->validateCategoryName($name);
        if (MediaCategory::where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => '分类名称已存在']);
        }

        return MediaCategory::create(['name' => $name, 'sort' => 0]);
    }

    /**
     * 修改分类名称。
     */
    public function renameCategory(int $id, string $name): MediaCategory
    {
        $category = MediaCategory::findOrFail($id);
        $name = trim($name);
        $this->validateCategoryName($name);
        if (MediaCategory::where('name', $name)->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['name' => '分类名称已存在']);
        }

        $category->update(['name' => $name]);

        return $category->fresh();
    }

    /**
     * 删除空分类;分类下有图片时抛异常并携带 media_count 提示先迁移。
     */
    public function deleteCategory(int $id): void
    {
        $category = MediaCategory::findOrFail($id);
        $mediaCount = $category->media()->count();
        if ($mediaCount > 0) {
            throw ValidationException::withMessages([
                'category' => "该分类下存在 {$mediaCount} 张图片,请先将图片迁移到其它分类后才能删除",
            ])->status(422);
        }

        $category->delete();
    }

    /**
     * 迁移分类下全部图片到目标分类后删除当前分类。
     * $targetId 为 null 表示迁移到"未分类"。
     */
    public function moveCategory(int $id, ?int $targetId): void
    {
        $category = MediaCategory::findOrFail($id);
        if ($targetId !== null && $targetId !== $id) {
            MediaCategory::findOrFail($targetId); // 校验目标存在
        }
        if ($targetId === $id) {
            throw ValidationException::withMessages(['category' => '目标分类不能与当前分类相同']);
        }

        $category->media()->update(['category_id' => $targetId]);
        $category->delete();
    }

    // ===== 素材管理 =====

    /**
     * 分页查询素材。
     * $filters: category_id(可空=全部,null 查询用 unset)、keyword、sort(created_at|filename|size)、order(desc|asc)。
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Media::query()->with('category:id,name');

        if (array_key_exists('category_id', $filters) && $filters['category_id'] !== '' && $filters['category_id'] !== null) {
            $query->where('category_id', (int) $filters['category_id']);
        } elseif (($filters['uncategorized'] ?? false)) {
            $query->whereNull('category_id');
        }

        if (! empty($filters['keyword'])) {
            $query->where('original_name', 'like', '%'.$filters['keyword'].'%');
        }

        $sort = in_array($filters['sort'] ?? '', ['filename', 'size'], true) ? $filters['sort'] : 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        return $query->paginate((int) ($filters['per_page'] ?? 24));
    }

    /**
     * 多文件上传入库。
     *
     * @param  UploadedFile[]  $files
     * @return Media[]
     */
    public function upload(array $files, ?int $categoryId = null): array
    {
        $this->validateCategoryId($categoryId);

        $saved = [];
        foreach ($files as $file) {
            $saved[] = $this->storeOne($file, $categoryId);
        }

        return $saved;
    }

    /**
     * 删除单张(物理删文件 + 删记录)。
     */
    public function delete(int $id): void
    {
        $media = Media::findOrFail($id);
        Storage::disk(self::DISK)->delete($media->path);
        $media->delete();
    }

    /**
     * 批量删除,返回删除条数。
     */
    public function batchDelete(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($media = Media::find($id)) {
                Storage::disk(self::DISK)->delete($media->path);
                $media->delete();
                $count++;
            }
        }

        return $count;
    }

    /**
     * 批量移动分类,返回移动条数。$categoryId 为 null 表示移到"未分类"。
     */
    public function batchMove(array $ids, ?int $categoryId): int
    {
        $this->validateCategoryId($categoryId);

        return Media::whereIn('id', $ids)->update(['category_id' => $categoryId]);
    }

    // ===== 内部工具 =====

    /** 单文件存储入库 */
    private function storeOne(UploadedFile $file, ?int $categoryId): Media
    {
        $originalName = $file->getClientOriginalName();
        $path = $file->store('media/'.date('Y/m'), self::DISK);

        // 存储文件名取磁盘上实际保存的名称(store 会按随机串命名)
        $filename = basename($path);

        // 图片尺寸
        $width = $height = null;
        $realPath = Storage::disk(self::DISK)->path($path);
        if (function_exists('getimagesize') && ($size = @getimagesize($realPath)) !== false) {
            $width = $size[0];
            $height = $size[1];
        }

        return Media::create([
            'category_id' => $categoryId,
            'original_name' => $originalName,
            'filename' => $filename,
            'path' => $path,
            'url' => '/storage/'.$path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);
    }

    /** 分类名校验(非空 + 长度) */
    private function validateCategoryName(string $name): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => '分类名称不能为空']);
        }
        if (mb_strlen($name) > self::CATEGORY_NAME_MAX) {
            throw ValidationException::withMessages(['name' => '分类名称不能超过 '.self::CATEGORY_NAME_MAX.' 个字']);
        }
    }

    /** 校验分类 ID 存在 */
    private function validateCategoryId(?int $categoryId): void
    {
        if ($categoryId !== null && ! MediaCategory::whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => '分类不存在']);
        }
    }
}
