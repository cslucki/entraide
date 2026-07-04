<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_service_images_uploaded_during_create(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser([
            'bio' => 'Test bio that is long enough for service creation.',
        ]);
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service with images',
            'description' => 'Description that is long enough to pass the minimum length validation requirement of one hundred characters.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
            'images' => [
                UploadedFile::fake()->image('photo1.jpg'),
                UploadedFile::fake()->image('photo2.png'),
            ],
        ]);

        $response->assertRedirect();

        $service = Service::where('title', 'Service with images')->first();
        $this->assertNotNull($service);
        $this->assertCount(2, $service->images);

        Storage::disk('public')->assertExists($service->images[0]->path);
        Storage::disk('public')->assertExists($service->images[1]->path);
    }

    public function test_service_show_page_displays_images(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser(['bio' => 'Test bio for image display test.']);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $service->images()->create([
            'path' => 'services/test-image.jpg',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('services/test-image.jpg', 'fake-content');

        $response = $this->get(route('services.show', $service));
        $response->assertOk();
        $response->assertSee($service->images[0]->url);
    }

    public function test_service_edit_page_displays_images(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser(['bio' => 'Test bio for edit display test.']);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $service->images()->create([
            'path' => 'services/edit-image.jpg',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('services/edit-image.jpg', 'fake-content');

        $response = $this->actingAs($user)->get(route('services.edit', $service));
        $response->assertOk();
        $response->assertSee($service->images[0]->url);
    }

    public function test_service_images_deleted_during_update(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser(['bio' => 'Test bio for delete image test.']);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $image = $service->images()->create([
            'path' => 'services/to-delete.jpg',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('services/to-delete.jpg', 'fake-content');

        $response = $this->actingAs($user)->put(route('services.update', $service), [
            'title' => $service->title,
            'description' => 'Updated description that is long enough to pass the minimum length validation requirement of at least one hundred characters as required by the controller validation rules.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 80,
            'status' => 'active',
            'delete_images' => [$image->id],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseMissing('service_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('services/to-delete.jpg');
    }

    public function test_request_attachments_uploaded_during_create(): void
    {
        Storage::fake('public');

        $user = $this->orgUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('requests.store'), [
            'title' => 'Request with attachments',
            'description' => 'Description that is long enough to pass the minimum length validation requirement of one hundred characters.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'budget_min' => 10,
            'budget_max' => 50,
            'attachments' => [
                UploadedFile::fake()->image('doc.jpg'),
                UploadedFile::fake()->create('file.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();

        $serviceRequest = ServiceRequest::where('title', 'Request with attachments')->first();
        $this->assertNotNull($serviceRequest);
        $this->assertCount(2, $serviceRequest->attachments);

        Storage::disk('public')->assertExists($serviceRequest->attachments[0]->path);
        Storage::disk('public')->assertExists($serviceRequest->attachments[1]->path);
    }

    public function test_request_show_page_displays_attachments(): void
    {
        Storage::fake('public');

        $user = $this->orgUser();
        $category = Category::factory()->create();
        $serviceRequest = ServiceRequest::factory()->forUser($user)->create([
            'category_id' => $category->id,
            'organization_id' => $this->testOrganization->id,
        ]);

        $attachment = $serviceRequest->attachments()->create([
            'path' => 'request-attachments/test-file.pdf',
            'original_name' => 'test-file.pdf',
            'mime_type' => 'application/pdf',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('request-attachments/test-file.pdf', 'fake-content');

        $response = $this->actingAs($user)->get(route('organization.dashboard.requests.detail', [$this->testOrganization, $serviceRequest]));
        $response->assertOk();
        $response->assertSee($attachment->original_name);
    }

    public function test_request_edit_page_displays_attachments(): void
    {
        Storage::fake('public');

        $user = $this->orgUser();
        $category = Category::factory()->create();
        $serviceRequest = ServiceRequest::factory()->forUser($user)->create([
            'category_id' => $category->id,
            'organization_id' => $this->testOrganization->id,
        ]);

        $attachment = $serviceRequest->attachments()->create([
            'path' => 'request-attachments/edit-file.pdf',
            'original_name' => 'edit-file.pdf',
            'mime_type' => 'application/pdf',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('request-attachments/edit-file.pdf', 'fake-content');

        $response = $this->actingAs($user)->get(route('requests.edit', $serviceRequest));
        $response->assertOk();
    }

    public function test_org_scoped_service_show_displays_images(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser(['bio' => 'Test bio for org show test.']);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $service->images()->create([
            'path' => 'services/org-image.jpg',
            'order' => 0,
            'organization_id' => $this->testOrganization->id,
        ]);

        Storage::disk('public')->put('services/org-image.jpg', 'fake-content');

        $response = $this->get(route('organization.services.show', [$this->testOrganization, $service]));
        $response->assertOk();
        $response->assertSee($service->images[0]->url);
    }

    public function test_uploads_validation_enforced(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->orgUser(['bio' => 'Test bio for validation test.']);
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service with invalid image',
            'description' => 'Description that is long enough to pass the minimum length validation requirement.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
            'images' => [
                UploadedFile::fake()->create('script.php', 100, 'text/plain'),
            ],
        ]);

        $response->assertSessionHasErrors('images.0');

        $tooManyImages = [];
        for ($i = 0; $i < 6; $i++) {
            $tooManyImages[] = UploadedFile::fake()->image("photo{$i}.jpg");
        }

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service with too many images',
            'description' => 'Description that is long enough to pass the minimum length validation requirement.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
            'images' => $tooManyImages,
        ]);

        $response->assertSessionHasErrors('images');
    }

    public function test_request_attachment_validation_enforced(): void
    {
        Storage::fake('public');

        $user = $this->orgUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('requests.store'), [
            'title' => 'Request with invalid attachment',
            'description' => 'Description that is long enough to pass the minimum length validation requirement.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'budget_min' => 10,
            'budget_max' => 50,
            'attachments' => [
                UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'),
            ],
        ]);

        $response->assertSessionHasErrors('attachments.0');
    }
}
