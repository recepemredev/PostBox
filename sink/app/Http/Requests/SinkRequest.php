<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\FailureSchedule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The sink's configuration, read from the query string of the URL the delivery
 * was addressed to.
 *
 * Query string only, never the body. A benchmark run delivers tenant payloads
 * the sink does not control, and a payload that happened to carry a `status`
 * field would otherwise change the sink's behaviour — a benchmark silently
 * measuring something other than what its script configured. `validationData`
 * narrows validation to the same source the accessors read, so the two can
 * never disagree about where a value came from.
 */
final class SinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'delay' => ['sometimes', 'integer', 'min:0', 'max:30000'],
            'fail_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'status' => ['sometimes', 'integer', 'min:400', 'max:599'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function delayMilliseconds(): int
    {
        return $this->query->getInt('delay');
    }

    public function failuresPerPeriod(): int
    {
        return FailureSchedule::failuresPerPeriod((float) $this->query->get('fail_rate', 0));
    }

    /**
     * The status a scheduled failure is answered with. 500 by default; 4xx is
     * allowed because PostBox's retry policy splits retryable from terminal on
     * exactly this value, and a run that never sees a terminal status never
     * exercises the split.
     */
    public function failureStatus(): int
    {
        return $this->query->getInt('status', 500);
    }
}
