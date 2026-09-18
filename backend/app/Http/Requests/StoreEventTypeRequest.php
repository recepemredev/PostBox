<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class StoreEventTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', EventType::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Same shape the database CHECK constraint holds this table to
            // (2026_09_10_000008): lowercase, dotted, at most 100 characters.
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+([._-][a-z0-9]+)*$/',
                'unique:event_types,name',
            ],
        ];
    }
}
