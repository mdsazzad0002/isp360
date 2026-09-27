<?php

// United States. Sales tax on internet access is barred by the Internet Tax Freedom Act; other fees
// (equipment rental, VoIP) may be taxed by state: add rates under Sales tax if they apply.
return [
    'language' => 'en',
    'date_format' => 'm/d/Y',
    'number_locale' => 'en-US',
    'phone' => ['calling_code' => '1', 'national_prefix' => '1', 'lengths' => [10], 'example' => '2015550123'],
    'address' => ['state_label' => 'State', 'postcode_label' => 'ZIP code', 'postcode_required' => true],
    'id_types' => ['drivers_license' => "Driver's license", 'state_id' => 'State ID', 'passport' => 'Passport', 'ssn_last4' => 'SSN (last 4)'],
    'tax' => ['label' => 'Sales tax', 'prices_include_tax' => false, 'rates' => []],
    'payment_gateways' => ['stripe', 'paypal'],
    'sms_providers' => ['Twilio', 'Vonage'],
    'billing' => ['due_days' => 15],
    'log_retention_days' => null,
    'regulatory_reports' => ['FCC Broadband Data Collection'],
];
