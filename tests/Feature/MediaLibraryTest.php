<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\MediaCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        Storage::fake('public');
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user->createToken('test')->plainTextToken;
    }

    // ===== 分类 =====

    public function test_category_crud(): void
    {
        $token = $this->adminToken();

        // 新增
        $resp = $this->withToken($token)->postJson('/api/admin/media-categories', ['name' => 'Logo']);
        $resp->assertStatus(201);
        $id = $resp->json('id');
        $this->assertDatabaseHas('media_categories', ['name' => 'Logo']);

        // 重名 → 422
        $this->withToken($token)->postJson('/api/admin/media-categories', ['name' => 'Logo'])
            ->assertStatus(422);

        // 超长 → 422
        $this->withToken($token)->postJson('/api/admin/media-categories', ['name' => str_repeat('长', 31)])
            ->assertStatus(422);

        // 改名
        $this->withToken($token)->putJson("/api/admin/media-categories/{$id}", ['name' => 'Banner'])
            ->assertOk();
        $this->assertDatabaseHas('media_categories', ['name' => 'Banner']);

        // 列表含数量
        $resp = $this->withToken($token)->getJson('/api/admin/media-categories');
        $resp->assertOk();
        $this->assertSame(1, count($resp->json('categories')));

        // 删除空分类
        $this->withToken($token)->deleteJson("/api/admin/media-categories/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('media_categories', ['id' => $id]);
    }

    public function test_delete_category_with_media_requires_migration(): void
    {
        $token = $this->adminToken();
        $category = MediaCategory::create(['name' => 'Logo']);
        Media::create([
            'category_id' => $category->id,
            'original_name' => 'a.png', 'filename' => 'a.png', 'path' => 'media/a.png',
            'url' => '/storage/media/a.png', 'mime_type' => 'image/png', 'size' => 100,
        ]);

        // 有图 → 422 + 提示
        $this->withToken($token)->deleteJson("/api/admin/media-categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '该分类下存在 1 张图片,请先将图片迁移到其它分类后才能删除');

        // 迁移到目标分类后删除
        $target = MediaCategory::create(['name' => 'Banner']);
        $this->withToken($token)->postJson("/api/admin/media-categories/{$category->id}/move", [
            'target_category_id' => $target->id,
        ])->assertOk();

        $this->assertSoftDeleted('media_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('media', ['category_id' => $target->id]);
    }

    public function test_move_category_to_uncategorized(): void
    {
        $token = $this->adminToken();
        $category = MediaCategory::create(['name' => 'Logo']);
        Media::create([
            'category_id' => $category->id,
            'original_name' => 'a.png', 'filename' => 'a.png', 'path' => 'media/a.png',
            'url' => '/storage/media/a.png', 'mime_type' => 'image/png', 'size' => 100,
        ]);

        $this->withToken($token)->postJson("/api/admin/media-categories/{$category->id}/move", [
            'target_category_id' => null,
        ])->assertOk();

        $this->assertSoftDeleted('media_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('media', ['category_id' => null]);
    }

    // ===== 素材上传/列表 =====

    public function test_upload_creates_media_records_with_metadata(): void
    {
        $token = $this->adminToken();
        $category = MediaCategory::create(['name' => 'Logo']);

        $resp = $this->withToken($token)->post('/api/admin/media/upload', [
            'files' => [UploadedFile::fake()->image('logo.png', 100, 50)],
            'category_id' => $category->id,
        ]);
        $resp->assertStatus(201);

        $media = $resp->json()[0];
        $this->assertSame('logo.png', $media['original_name']);
        $this->assertSame($category->id, $media['category_id']);
        $this->assertSame(100, $media['width']);
        $this->assertSame(50, $media['height']);
        $this->assertStringStartsWith('/storage/media/', $media['url']);
        $this->assertStringEndsWith('.png', $media['url']);
        // 文件真实落盘
        Storage::disk('public')->assertExists(ltrim($media['path'], '/'));
    }

    public function test_upload_rejects_non_image(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->post('/api/admin/media/upload', [
            'files' => [UploadedFile::fake()->create('doc.txt', 10)],
        ])->assertStatus(422);
    }

    public function test_media_list_search_sort_paginate(): void
    {
        $token = $this->adminToken();
        $category = MediaCategory::create(['name' => 'Logo']);
        for ($i = 1; $i <= 3; $i++) {
            Media::create([
                'category_id' => $category->id,
                'original_name' => "banner{$i}.png", 'filename' => "b{$i}.png",
                'path' => "media/b{$i}.png", 'url' => "/storage/media/b{$i}.png",
                'mime_type' => 'image/png', 'size' => $i * 100,
            ]);
        }

        // 分类筛选
        $resp = $this->withToken($token)->getJson('/api/admin/media?category_id='.$category->id);
        $resp->assertOk()->assertJsonCount(3, 'data');

        // 关键词搜索
        $resp = $this->withToken($token)->getJson('/api/admin/media?keyword=banner1');
        $resp->assertOk()->assertJsonCount(1, 'data');

        // 按大小升序
        $resp = $this->withToken($token)->getJson('/api/admin/media?sort=size&order=asc');
        $resp->assertOk();
        $this->assertSame(100, $resp->json('data.0.size'));

        // 未分类筛选
        Media::create([
            'category_id' => null, 'original_name' => 'free.png', 'filename' => 'f.png',
            'path' => 'media/f.png', 'url' => '/storage/media/f.png',
            'mime_type' => 'image/png', 'size' => 1,
        ]);
        $resp = $this->withToken($token)->getJson('/api/admin/media?uncategorized=1');
        $resp->assertOk()->assertJsonCount(1, 'data');
    }

    // ===== 删除 =====

    public function test_delete_removes_file_and_record(): void
    {
        $token = $this->adminToken();
        $resp = $this->withToken($token)->post('/api/admin/media/upload', [
            'files' => [UploadedFile::fake()->image('del.png')],
        ]);
        $id = $resp->json()[0]['id'];
        $path = $resp->json()[0]['path'];
        Storage::disk('public')->assertExists($path);

        $this->withToken($token)->deleteJson("/api/admin/media/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('media', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_batch_delete_and_batch_move(): void
    {
        $token = $this->adminToken();
        $resp = $this->withToken($token)->post('/api/admin/media/upload', [
            'files' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
            ],
        ]);
        $ids = array_column($resp->json(), 'id');

        // 批量移动
        $target = MediaCategory::create(['name' => '产品']);
        $this->withToken($token)->postJson('/api/admin/media/batch-move', [
            'ids' => $ids, 'category_id' => $target->id,
        ])->assertOk();
        $this->assertSame(2, Media::where('category_id', $target->id)->count());

        // 批量删除
        $this->withToken($token)->postJson('/api/admin/media/batch-delete', ['ids' => $ids])
            ->assertOk();
        $this->assertSame(0, Media::count());
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/admin/media')->assertStatus(401);
        $this->postJson('/api/admin/media-categories', ['name' => 'X'])->assertStatus(401);
    }
}
