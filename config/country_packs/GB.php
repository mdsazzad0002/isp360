<?php

// United Kingdom. VAT 20%; consumer prices are shown including VAT.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'number_locale' => 'en-GB',
    'phone' => ['calling_code' => '44', 'national_prefix' => '0', 'lengths' => [10], 'example' => '07400123456'],
    'address' => ['state_label' => 'County', 'postcode_label' => 'Postcode', 'postcode_required' => true],
    'id_types' => ['passport' => 'Passport', 'driving_licence' => 'Driving licence'],
    'tax' => ['label' => 'VAT', 'prices_include_tax' => true, 'rates' => [
        ['name' => 'VAT 20%', 'rate' => 20, 'default' => true],
    ]],
    'payment_gateways' => ['stripe', 'paypal', 'gocardless'],
    'sms_providers' => ['Twilio', 'Vonage'],
    'billing' => ['due_days' => 14],
    'log_retention_days' => 365,
    'regulatory_reports' => ['Ofcom Connected Nations data'],
];
