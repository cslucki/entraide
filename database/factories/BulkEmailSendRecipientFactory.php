<?php

namespace Database\Factories;

use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BulkEmailSendRecipientFactory extends Factory
{
    protected $model = BulkEmailSendRecipient::class;

    public function definition(): array
    {
        return [
            'bulk_email_send_id' => BulkEmailSend::factory(),
            'user_id' => User::factory(),
            'status' => BulkEmailSendRecipient::STATUS_PENDING,
        ];
    }
}
