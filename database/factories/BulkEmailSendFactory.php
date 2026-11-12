<?php

namespace Database\Factories;

use App\Models\BulkEmailSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BulkEmailSendFactory extends Factory
{
    protected $model = BulkEmailSend::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'member_filter' => BulkEmailSend::MEMBER_FILTER_ALL,
            'content_source' => BulkEmailSend::CONTENT_SOURCE_CUSTOM,
            'subject' => fake()->sentence(),
            'content_format' => BulkEmailSend::CONTENT_FORMAT_PLAIN,
            'content_raw' => fake()->paragraph(),
            'status' => BulkEmailSend::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
