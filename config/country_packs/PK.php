<?php

// Pakistan. Sales tax on telecom services is provincial and differs by province: add it under Sales tax.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'phone' => ['calling_code' => '92', 'national_prefix' => '0', 'lengths' => [10], 'example' => '03001234567'],
    'address' => ['state_label' => 'Province', 'postcode_label' => 'Postal code', 'postcode_required' => false],
    'id_types' => ['cnic' => 'CNIC', 'passport' => 'Passport'],
    'tax' => ['label' => 'Sales tax', 'prices_include_tax' => false, 'rates' => []],
    'payment_gateways' => ['jazzcash', 'easypaisa'],
    'sms_providers' => ['Jazz', 'Telenor', 'Zong'],
    'billing' => [],
    'log_retention_days' => 365,
    'regulatory_reports' => ['PTA subscriber report'],
];
