<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'purchase.invoice' => 'required',
            'purchase.date' => 'required',
            'purchase.paid' => 'required',
            'carts' => 'required|array'
        ];

        if ($this->supplier['type'] == 'new') {
            $rules['supplier.name'] = 'required';
            $rules['supplier.phone'] = 'required';
        }
        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'purchase.date.required' => 'Purchase date required',
            'purchase.paid.required' => 'Purchase paid required',
            'supplier.name.required' => 'Supplier name required',
            'supplier.phone.required' => 'Supplier phone required',
        ];
    }
}
