<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => [
                'required',
                'integer',
            ],
            'status' => [
                'required',
                'string',
                'in:paid,failed',
            ],
            'transaction_id' => [
                'required',
                'string',
                'max:255',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
