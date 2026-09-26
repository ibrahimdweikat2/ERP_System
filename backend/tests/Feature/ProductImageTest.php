<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductImage;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function login(string $role = 'owner'): User
    {
        $u = User::factory()->create(['password' => Hash::make('SecurePass123!')]);
        $u->roles()->attach(Role::where('name', $role)->firstOrFail());
        $this->actingAs($u);

        return $u;
    }

    private function product(): Product
    {
        $unit = Unit::firstOrFail();

        return Product::create(['sku' => 'LG-REF-500', 'name_ar' => 'ثلاجة', 'unit_id' => $unit->id, 'serial_tracked' => true,
            'standard_cost' => '0', 'cash_price' => '1000', 'installment_price' => '1200', 'minimum_price' => '900', 'reorder_level' => '0', 'active' => true,
            'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
    }

    private function image(string $name = 'product.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    public function test_image_is_optional_and_upload_replace_delete_keep_one_file(): void
    {
        $this->login();
        $product = $this->product();
        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.image_url', null);

        $this->post("/api/v1/products/{$product->id}/image", ['file' => $this->image()])->assertCreated();
        $first = ProductImage::where('product_id', $product->id)->firstOrFail()->stored_path;
        $this->assertNotNull($first);
        $this->assertTrue(Storage::disk('public')->exists($first));
        $shown = $this->getJson("/api/v1/products/{$product->id}")->assertJsonMissingPath('data.image')->json('data.image_url');
        $this->assertStringEndsWith($first, $shown);

        // The same bytes are deduplicated: only different content replaces the image.
        $this->post("/api/v1/products/{$product->id}/image", ['file' => $this->image()])->assertCreated();
        $this->assertSame($first, ProductImage::where('product_id', $product->id)->firstOrFail()->stored_path);

        $this->post("/api/v1/products/{$product->id}/image", ['file' => UploadedFile::fake()->image('replacement.png', 64, 48)])->assertCreated();
        $second = ProductImage::where('product_id', $product->id)->firstOrFail()->stored_path;
        $this->assertNotSame($first, $second);
        $this->assertFalse(Storage::disk('public')->exists($first));
        $this->assertCount(1, Storage::disk('public')->allFiles("products/{$product->id}"));

        $this->deleteJson("/api/v1/products/{$product->id}/image")->assertOk();
        $this->assertFalse(ProductImage::where('product_id', $product->id)->exists());
        $this->assertSame([], Storage::disk('public')->allFiles("products/{$product->id}"));
        $this->deleteJson("/api/v1/products/{$product->id}/image")->assertNotFound()->assertJsonPath('code', 'PRODUCT_IMAGE_MISSING');
    }

    public function test_only_real_images_are_accepted_and_the_image_cannot_be_set_through_the_product_form(): void
    {
        $this->login();
        $product = $this->product();
        $this->post("/api/v1/products/{$product->id}/image", ['file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])->assertUnprocessable();
        // A PNG name over non-image bytes must fail on content, not only on extension.
        $this->post("/api/v1/products/{$product->id}/image", ['file' => UploadedFile::fake()->createWithContent('fake.png', 'not-an-image')])->assertUnprocessable();
        $this->post("/api/v1/products/{$product->id}/image", ['file' => UploadedFile::fake()->image('huge.png', 40, 40)->size(6000)])->assertUnprocessable();
        $this->assertFalse(ProductImage::where('product_id', $product->id)->exists());
        $this->assertSame([], Storage::disk('public')->allFiles("products/{$product->id}"));

        $this->postJson("/api/v1/products/{$product->id}/image", [])->assertUnprocessable();
        $payload = [...$product->only(['sku', 'name_ar', 'unit_id', 'serial_tracked', 'active', 'cash_price', 'installment_price', 'minimum_price', 'reorder_level']), 'barcodes' => [], 'image' => 1];
        $this->putJson("/api/v1/products/{$product->id}", $payload)->assertUnprocessable();
    }

    public function test_catalog_permissions_are_enforced_on_every_image_route(): void
    {
        $this->login();
        $product = $this->product();
        $this->post("/api/v1/products/{$product->id}/image", ['file' => $this->image()])->assertCreated();

        $this->login('cashier');
        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.image_url', fn ($v) => is_string($v));
        $this->post("/api/v1/products/{$product->id}/image", ['file' => $this->image()])->assertForbidden();
        $this->deleteJson("/api/v1/products/{$product->id}/image")->assertForbidden();
        $this->assertTrue(ProductImage::where('product_id', $product->id)->exists());
    }
}
