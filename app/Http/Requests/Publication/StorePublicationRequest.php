<?php

namespace App\Http\Requests\Publication;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicationRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'severity' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'description' => 'required|string',
            'url_imagen' => 'required|string',
            'date' => 'required|date',
            'profile_id' => 'required|int|exists:profiles,id',
            'categories' => 'required|array',
            'categories.*' => 'exists:categories,id'
        ];
    }
}
