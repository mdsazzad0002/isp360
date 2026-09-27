<?php

// Nigeria. VAT 7.5%.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'number_locale' => 'en-NG',
    'phone' => ['calling_code' => '234', 'national_prefix' => '0', 'lengths' => [10], 'example' => '08031234567'],
    'address' => ['state_label' => 'State', 'postcode_label' => 'Postal code', 'postcode_required' => false],
    'id_types' => ['nin' => 'NIN', 'voters_card' => "Voter's card", 'drivers_licence' => "Driver's licence", 'passport' => 'Passport'],
    'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'VAT 7.5%', 'rate' => 7.5, 'default' => true],
    ]],
    'payment_gateways' => ['paystack', 'flutterwave'],
    'sms_providers' => ["Termii", "Africa's Talking"],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['NCC subscriber data report'],
];
