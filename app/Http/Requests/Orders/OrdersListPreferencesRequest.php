<?php

namespace App\Http\Requests\Orders;

use App\Support\OrdersListPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The employee's own view of the orders list; it grants nothing, so any signed-in
 * employee may save it. Column keys are not checked against the columns: the page
 * ignores unknown ones.
 */
class OrdersListPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $max = OrdersListPreferences::MAX_COLUMNS;
        $key = ['string', 'max:'.OrdersListPreferences::MAX_KEY_LENGTH, 'distinct'];

        return [
            'view' => ['required', Rule::in(['table', 'cards'])],
            'density' => ['sometimes', Rule::in(OrdersListPreferences::DENSITIES)],
            'filters' => ['required', 'boolean'],
            'columns' => ['nullable', 'array', "max:{$max}"],
            'columns.*' => ['boolean'],
            'order' => ['nullable', 'array', 'list', "max:{$max}"],
            'order.*' => $key,
            'pinning' => ['nullable', 'array:start,end'],
            'pinning.start' => ['nullable', 'array', 'list', "max:{$max}"],
            'pinning.start.*' => $key,
            'pinning.end' => ['nullable', 'array', 'list', "max:{$max}"],
            'pinning.end.*' => $key,
            'sort' => ['nullable', Rule::in(OrdersListPreferences::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'view' => 'вид списка',
            'filters' => 'строка фильтров',
            'columns' => 'видимость колонок',
            'order' => 'порядок колонок',
            'pinning' => 'закрепление колонок',
            'sort' => 'сортировка',
            'direction' => 'направление сортировки',
        ];
    }
}
