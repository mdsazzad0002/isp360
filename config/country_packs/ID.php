<?php

// Indonesia. PPN (VAT) 11% effective rate.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'phone' => ['calling_code' => '62', 'national_prefix' => '0', 'lengths' => [9, 10, 11, 12], 'example' => '081234567890'],
    'address' => ['state_label' => 'Province', 'postcode_label' => 'Postal code', 'postcode_required' => true],
    'id_types' => ['ktp' => 'KTP (NIK)', 'npwp' => 'NPWP', 'passport' => 'Passport'],
    'tax' => ['label' => 'PPN', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'PPN 11%', 'rate' => 11, 'default' => true],
    ]],
    'payment_gateways' => ['xendit', 'midtrans'],
    'sms_providers' => ['Zenziva', 'Twilio'],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['Komdigi subscriber report'],
];
