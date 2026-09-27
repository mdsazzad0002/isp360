<?php

// Bangladesh. Reproduces the app's original behaviour: BDT, Asia/Dhaka, no tax rates, prepaid, no grace.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'phone' => ['calling_code' => '880', 'national_prefix' => '0', 'lengths' => [10], 'example' => '01712345678'],
    'address' => ['state_label' => 'Division', 'postcode_label' => 'Post code', 'postcode_required' => false],
    'id_types' => ['nid' => 'National ID (NID)', 'birth_certificate' => 'Birth certificate', 'passport' => 'Passport'],
    // VAT on ISP services has changed between budgets, so no rate is suggested: add the current one under Sales tax.
    'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => []],
    'payment_gateways' => ['bkash', 'nagad', 'rocket', 'sslcommerz'],
    'sms_providers' => ['BulkSMSBD', 'SSL Wireless', 'Alpha SMS'],
    'billing' => ['due_days' => 10, 'renewal_invoice_days' => 3, 'auto_suspend' => true, 'auto_reactivate' => true],
    'log_retention_days' => 365,
    'regulatory_reports' => ['BTRC monthly subscriber report'],
];
