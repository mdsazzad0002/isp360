<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        $sale = $this->normalizeInput($this->sale);
        $customer = $this->normalizeInput($this->customer);
        $carts = $this->normalizeInput($this->carts);
        $bankCart = $this->normalizeInput($this->bankCart);

        $this->merge([
            'sale' => $sale,
            'customer' => $customer,
            'carts' => $carts,
            'bankCart' => $bankCart,
        ]);
    }

    private function normalizeInput($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return $value;
    }
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
        $customer = $this->input('customer', []);

        $rules = [
            'sale' => 'required',
            'customer' => 'required',
            'carts' => 'required',
        ];

        if (is_array($customer) && ($customer['type'] ?? null) === 'new') {
            $rules['customer.name'] = 'required';
            $rules['customer.phone'] = 'required';
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
            'sale.date.required' => 'Sale date required',
            'sale.paid.required' => 'Sale paid required',
            'customer.name.required' => 'Customer name required',
            'customer.phone.required' => 'Customer phone required',
        ];
    }
}
