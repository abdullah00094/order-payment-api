<?php

namespace App\Http\Requests;

use App\Payments\PaymentGatewayResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $availableMethods = app(PaymentGatewayResolver::class)->availableMethods();

        return [
            'method' => ['required', 'string', Rule::in($availableMethods)],
            'outcome' => ['required', 'string', Rule::in(array_keys(config('payment.outcomes', [])))],
        ];
    }
}
