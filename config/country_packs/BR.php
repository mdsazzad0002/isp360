<?php

// Brazil. Telecom taxes (ICMS, PIS, COFINS, FUST, FUNTTEL) differ by state and regime, and consumer
// prices are shown with taxes included: add the rates that apply under Sales tax.
return [
    'language' => 'en',
    'date_format' => 'd/m/Y',
    'number_locale' => 'pt-BR',
    'phone' => ['calling_code' => '55', 'national_prefix' => '', 'lengths' => [10, 11], 'example' => '11912345678'],
    'address' => ['state_label' => 'State (UF)', 'postcode_label' => 'CEP', 'postcode_required' => true],
    'id_types' => ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'rg' => 'RG'],
    'tax' => ['label' => 'Tributos', 'prices_include_tax' => true, 'rates' => []],
    'payment_gateways' => ['mercadopago', 'pix'],
    'sms_providers' => ['Zenvia', 'Twilio'],
    'billing' => [],
    // Marco Civil da Internet: connection logs for 1 year
    'log_retention_days' => 365,
    'regulatory_reports' => ['Anatel monthly access report'],
];
