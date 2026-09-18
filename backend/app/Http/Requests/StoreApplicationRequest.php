<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Application::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Scoped to the current tenant by Row Level Security, the same
            // way PublishMessageRequest's event_type check needs no explicit
            // tenant predicate of its own.
            'name' => ['required', 'string', 'max:255', 'unique:applications,name'],
        ];
    }
}
