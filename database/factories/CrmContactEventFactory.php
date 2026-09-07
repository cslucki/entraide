<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmContactEvent>
 */
class CrmContactEventFactory extends Factory
{
    protected $model = CrmContactEvent::class;

    public function definition(): array
    {
        $contact = CrmContact::factory();

        return [
            'crm_contact_id' => $contact,
            'organization_id' => fn (array $attrs) => CrmContact::find($attrs['crm_contact_id'])?->organization_id,
            'type' => CrmContactEvent::TYPE_STATUS_CHANGED,
            'payload' => ['from_status_id' => null, 'from_label' => null, 'to_status_id' => null, 'to_label' => 'Nouveau'],
            'occurred_at' => now(),
        ];
    }
}
