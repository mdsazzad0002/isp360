<?php

// Currencies the company can bill in (ISO 4217 code => display data). Money columns hold 3
// decimals; App\Support\Money rounds every amount to the currency's own decimals (0-3).
// A symbol that ends in a letter ("Tk", "Rs") is printed with a space: "Tk 1,200.00", "$1,200.00".
return [
    'BDT' => ['name' => 'Bangladeshi Taka', 'symbol' => 'Tk', 'decimals' => 2],
    'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
    'PKR' => ['name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimals' => 2],
    'NPR' => ['name' => 'Nepalese Rupee', 'symbol' => 'Rs', 'decimals' => 2],
    'LKR' => ['name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'decimals' => 2],
    'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
    'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
    'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
    'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimals' => 2],
    'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2],
    'AED' => ['name' => 'UAE Dirham', 'symbol' => 'AED', 'decimals' => 2],
    'SAR' => ['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'decimals' => 2],
    'QAR' => ['name' => 'Qatari Riyal', 'symbol' => 'QAR', 'decimals' => 2],
    'MYR' => ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimals' => 2],
    'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimals' => 2],
    'PHP' => ['name' => 'Philippine Peso', 'symbol' => '₱', 'decimals' => 2],
    'THB' => ['name' => 'Thai Baht', 'symbol' => '฿', 'decimals' => 2],
    'NGN' => ['name' => 'Nigerian Naira', 'symbol' => '₦', 'decimals' => 2],
    'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'decimals' => 2],
    'GHS' => ['name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'decimals' => 2],
    'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2],
    'EGP' => ['name' => 'Egyptian Pound', 'symbol' => 'E£', 'decimals' => 2],
    'TRY' => ['name' => 'Turkish Lira', 'symbol' => '₺', 'decimals' => 2],
    'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$', 'decimals' => 2],
    'MXN' => ['name' => 'Mexican Peso', 'symbol' => 'MX$', 'decimals' => 2],
    // no minor unit in use
    'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
    'KRW' => ['name' => 'South Korean Won', 'symbol' => '₩', 'decimals' => 0],
    'VND' => ['name' => 'Vietnamese Dong', 'symbol' => '₫', 'decimals' => 0],
    'IDR' => ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimals' => 0], // ISO says 2; sen are not used
    // 3 decimals (fils / baisa)
    'KWD' => ['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimals' => 3],
    'BHD' => ['name' => 'Bahraini Dinar', 'symbol' => 'BD', 'decimals' => 3],
    'OMR' => ['name' => 'Omani Rial', 'symbol' => 'OMR', 'decimals' => 3],
    'JOD' => ['name' => 'Jordanian Dinar', 'symbol' => 'JD', 'decimals' => 3],
];
