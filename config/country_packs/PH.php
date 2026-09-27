<?php

// Philippines. VAT 12%.
return [
    'language' => 'en',
    'date_format' => 'm/d/Y',
    'number_locale' => 'en-PH',
    'phone' => ['calling_code' => '63', 'national_prefix' => '0', 'lengths' => [10], 'example' => '09171234567'],
    'address' => ['state_label' => 'Province', 'postcode_label' => 'ZIP code', 'postcode_required' => false],
    'id_types' => ['philsys' => 'PhilSys ID', 'drivers_license' => "Driver's license", 'umid' => 'UMID', 'passport' => 'Passport'],
    'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'VAT 12%', 'rate' => 12, 'default' => true],
    ]],
    'payment_gateways' => ['paymongo', 'xendit', '2c2p'],
    'sms_providers' => ['Semaphore', 'Globe Labs'],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['NTC subscriber report'],
];
