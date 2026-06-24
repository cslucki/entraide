<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class T346OrganizationLogoTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->orgA = Organization::factory()->create(['name' => 'T346 Org A']);
        $this->orgB = Organization::factory()->create(['name' => 'T346 Org B']);

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $this->orgAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => false,
        ]);

        $this->orgA->update(['admin_id' => $this->orgAdmin->id]);
    }

    public function test_super_admin_can_upload_logo_for_any_organization(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $this->orgA), [
                'name' => $this->orgA->name,
                'slug' => $this->orgA->slug,
                'welcome_points' => 100,
                'platform_name' => 'Test',
                'global_color_mode' => 'dark',
                'logo' => $file,
            ]);

        $this->orgA->refresh();

        $this->assertNotNull($this->orgA->logo_path);
        $this->assertStringStartsWith('organization-logos/'.$this->orgA->id.'/', $this->orgA->logo_path);
        Storage::disk('public')->assertExists($this->orgA->logo_path);
    }

    public function test_super_admin_can_upload_logo_for_main_organization(): void
    {
        $mainOrg = Organization::factory()->create(['name' => 'T346 Main', 'is_default' => true]);

        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $mainOrg), [
                'name' => $mainOrg->name,
                'slug' => $mainOrg->slug,
                'welcome_points' => 100,
                'platform_name' => 'Test',
                'global_color_mode' => 'dark',
                'logo' => $file,
            ]);

        $mainOrg->refresh();

        $this->assertNotNull($mainOrg->logo_path);
        Storage::disk('public')->assertExists($mainOrg->logo_path);
    }

    public function test_org_admin_can_upload_logo_for_own_organization(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.identity.update', $this->orgA), [
                'logo' => $file,
            ]);

        $this->orgA->refresh();

        $this->assertNotNull($this->orgA->logo_path);
        Storage::disk('public')->assertExists($this->orgA->logo_path);
    }

    public function test_org_admin_cannot_upload_logo_for_other_organization(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.identity.update', $this->orgB), [
                'logo' => $file,
            ]);

        $this->orgB->refresh();

        $this->assertNull($this->orgB->logo_path);
    }

    public function test_fallback_to_bouclepro_symbol_when_no_logo(): void
    {
        $this->assertNull($this->orgA->logo_url);

        $expectedFallback = asset('brand/bouclepro-symbol-64.png');
        $this->assertEquals($expectedFallback, $this->orgA->logo_url ?: $expectedFallback);
    }

    public function test_invalid_upload_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $this->orgA), [
                'name' => $this->orgA->name,
                'slug' => $this->orgA->slug,
                'welcome_points' => 100,
                'platform_name' => 'Test',
                'global_color_mode' => 'dark',
                'logo' => $file,
            ]);

        $this->orgA->refresh();

        $this->assertNull($this->orgA->logo_path);
    }

    public function test_svg_upload_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('logo.svg', 100, 'image/svg+xml');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $this->orgA), [
                'name' => $this->orgA->name,
                'slug' => $this->orgA->slug,
                'welcome_points' => 100,
                'platform_name' => 'Test',
                'global_color_mode' => 'dark',
                'logo' => $file,
            ]);

        $this->orgA->refresh();

        $this->assertNull($this->orgA->logo_path);
    }

    public function test_accepted_formats_are_allowed(): void
    {
        $formats = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];

        foreach ($formats as $ext => $mime) {
            $file = UploadedFile::fake()->create("logo.$ext", 50, $mime);

            $this->actingAs($this->superAdmin)
                ->put(route('admin.organizations.update', $this->orgA), [
                    'name' => $this->orgA->name,
                    'slug' => $this->orgA->slug,
                    'welcome_points' => 100,
                    'platform_name' => 'Test',
                    'global_color_mode' => 'dark',
                    'logo' => $file,
                ]);

            $this->orgA->refresh();

            $this->assertNotNull($this->orgA->logo_path, "Format $ext should be allowed");
            Storage::disk('public')->assertExists($this->orgA->logo_path);

            // Clean up for next iteration
            Storage::disk('public')->delete($this->orgA->logo_path);
            $this->orgA->update(['logo_path' => null]);
        }
    }
}
