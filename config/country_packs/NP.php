<?php

// Nepal. VAT 13%.
return [
    'language' => 'en',
    'date_format' => 'Y-m-d',
    'number_locale' => 'en-US',
    'phone' => ['calling_code' => '977', 'national_prefix' => '', 'lengths' => [10], 'example' => '9801234567'],
    'address' => ['state_label' => 'Province', 'postcode_label' => 'Postal code', 'postcode_required' => false],
    'id_types' => ['citizenship' => 'Citizenship certificate', 'national_id' => 'National ID', 'passport' => 'Passport'],
    'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'VAT 13%', 'rate' => 13, 'default' => true],
    ]],
    'payment_gateways' => ['esewa', 'khalti'],
    'sms_providers' => ['Sparrow SMS', 'Aakash SMS'],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['NTA subscriber report'],
];
