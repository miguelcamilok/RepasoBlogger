<?php

namespace App\Http\Requests\Publication;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicationRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:255',
            'severity ' => 'sometimes|string|max:255',
            'location ' => 'sometimes|string|max:255',
            'description ' => 'sometimes|text',
            'url_imagen ' => 'sometimes|text',
            'date' => 'sometimes|date',
            'profile_id' => 'sometimes|int|exists:profiles,id',
            'categories' => 'sometimes|array',
            'categories.*' => 'exists:categories,id',
        ];
    }
}
