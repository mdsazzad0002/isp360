<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'adjustment.invoice' => 'required',
            'adjustment.date' => 'required',
            'carts' => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'adjustment.date.required' => 'Adjustment date required',
            'carts.required' => 'At least one product is required',
        ];
    }
}
