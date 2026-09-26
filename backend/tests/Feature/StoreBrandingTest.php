<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\Role;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', 'owner')->firstOrFail());

        return $u;
    }

    public function test_platform_name_is_saved_and_exposed_in_context(): void
    {
        $this->actingAs($this->owner());
        $settings = $this->getJson('/api/v1/store/settings')->assertOk()->json('data');
        $payload = collect($settings)->only(['trade_name', 'legal_name', 'owner_name', 'address', 'phone', 'email', 'tax_number', 'vat_registered', 'base_currency', 'timezone', 'locale', 'price_display', 'invoice_footer'])->all();
        $this->putJson('/api/v1/store/settings', [...$payload, 'trade_name' => 'متجر الاختبار', 'platform_name' => 'متجري'])->assertOk();
        $this->getJson('/api/v1/store/context')->assertOk()->assertJsonPath('data.platform_name', 'متجري')->assertJsonPath('data.logo_url', null);
    }

    public function test_logo_upload_replace_and_remove(): void
    {
        $this->actingAs($this->owner());
        $first = $this->post('/api/v1/store/logo', ['file' => UploadedFile::fake()->image('a.png', 64, 64)], ['Accept' => 'application/json'])->assertCreated()->json('data.logo_url');
        $firstPath = StoreSetting::findOrFail(1)->logo_path;
        Storage::disk('public')->assertExists($firstPath);
        $this->getJson('/api/v1/store/context')->assertJsonPath('data.logo_url', $first);

        $this->post('/api/v1/store/logo', ['file' => UploadedFile::fake()->image('b.png', 64, 64)], ['Accept' => 'application/json'])->assertCreated();
        Storage::disk('public')->assertMissing($firstPath);

        $this->deleteJson('/api/v1/store/logo')->assertOk();
        $this->assertNull(StoreSetting::findOrFail(1)->logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('branding'));
    }

    public function test_logo_rejects_non_images(): void
    {
        $this->actingAs($this->owner());
        $this->post('/api/v1/store/logo', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_public_branding_exposes_only_name_and_logo(): void
    {
        $this->actingAs($this->owner());
        $this->post('/api/v1/store/logo', ['file' => UploadedFile::fake()->image('a.png', 64, 64)], ['Accept' => 'application/json'])->assertCreated();
        StoreSetting::whereKey(1)->update(['platform_name' => 'متجري', 'phone' => '0599000000']);
        auth()->guard('web')->logout();
        $data = $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.platform_name', 'متجري')->json('data');
        $this->assertSame(['platform_name', 'logo_url'], array_keys($data));
        $this->assertNotNull($data['logo_url']);
    }
}
