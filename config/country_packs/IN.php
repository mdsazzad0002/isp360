<?php

// India. GST 18% on internet service, split CGST + SGST within a state (IGST 18% across states).
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'number_locale' => 'en-IN',
    'phone' => ['calling_code' => '91', 'national_prefix' => '0', 'lengths' => [10], 'example' => '9812345678'],
    'address' => ['state_label' => 'State', 'postcode_label' => 'PIN code', 'postcode_required' => true],
    'id_types' => ['aadhaar' => 'Aadhaar', 'pan' => 'PAN', 'voter_id' => 'Voter ID', 'driving_licence' => 'Driving licence', 'passport' => 'Passport'],
    'tax' => ['label' => 'GST', 'prices_include_tax' => false, 'rates' => [
        ['name' => 'CGST 9%', 'rate' => 9, 'default' => true],
        ['name' => 'SGST 9%', 'rate' => 9, 'default' => true],
    ]],
    'payment_gateways' => ['razorpay', 'payu', 'cashfree', 'phonepe'],
    'sms_providers' => ['MSG91', 'Gupshup', 'Textlocal'],
    'billing' => ['due_days' => 15],
    // DoT licence: 2 years of IP/session logs
    'log_retention_days' => 730,
    'regulatory_reports' => ['TRAI performance monitoring report', 'DoT subscriber report'],
];
