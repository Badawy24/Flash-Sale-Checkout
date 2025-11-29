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
            'order_id' => 'required|exists:orders,id',
            'status' => 'required|in:paid,failed',
            'transaction_id' => 'required|string',
            'idempotency_key' => 'required|string',
        ];
    }
}
