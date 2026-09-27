<?php

// Kenya. VAT 16% (excise duty on internet data is not included: add it under Sales tax if it applies).
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'phone' => ['calling_code' => '254', 'national_prefix' => '0', 'lengths' => [9], 'example' => '0712345678'],
    'address' => ['state_label' => 'County', 'postcode_label' => 'Postal code', 'postcode_required' => false],
    'id_types' => ['national_id' => 'National ID', 'kra_pin' => 'KRA PIN', 'passport' => 'Passport'],
    'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'VAT 16%', 'rate' => 16, 'default' => true],
    ]],
    'payment_gateways' => ['mpesa', 'paystack', 'flutterwave'],
    'sms_providers' => ["Africa's Talking"],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['CA quarterly sector statistics'],
];
