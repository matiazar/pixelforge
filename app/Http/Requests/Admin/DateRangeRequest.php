<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Optional ?from=Y-m-d&to=Y-m-d, both inclusive. Missing dates fall back to
 * the last defaultDays() days ending today in timezone().
 */
abstract class DateRangeRequest extends AdminRequest
{
    abstract protected function timezone(): string;

    abstract protected function defaultDays(): int;

    abstract protected function maxDays(): int;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Both or neither: a half range would silently mix a date with a default.
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->from()->gt($this->to())) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');

                return;
            }

            $days = (int) $this->from()->diffInDays($this->to()) + 1;

            if ($days > $this->maxDays()) {
                $validator->errors()->add('to', "The range can be at most {$this->maxDays()} days.");
            }
        }];
    }

    public function from(): CarbonImmutable
    {
        $from = $this->query('from');

        return is_string($from)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $from, $this->timezone())
            : $this->to()->subDays($this->defaultDays() - 1);
    }

    public function to(): CarbonImmutable
    {
        $to = $this->query('to');

        return is_string($to)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $this->timezone())
            : CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * @return array{from: string, to: string}
     */
    public function filters(): array
    {
        return ['from' => $this->from()->toDateString(), 'to' => $this->to()->toDateString()];
    }
}
