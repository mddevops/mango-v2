<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Display order of reference values, changed by dragging rows. The client sends the
 * rows it sees (one page, maybe filtered) in their new order; those rows swap the
 * positions they already held, every other row keeps its place, and the whole table
 * is renumbered in steps of STEP, which also removes ties of the old order.
 */
class ReferenceOrder
{
    public const STEP = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     * @param  list<string>  $orderBy  the same columns the list is displayed by
     */
    public function reorder(string $class, array $ids, string $auditSubject, string $label, array $orderBy = ['sort_order', 'id']): void
    {
        DB::transaction(function () use ($class, $ids, $auditSubject, $label, $orderBy) {
            $query = $class::query()->lockForUpdate();

            foreach ($orderBy as $column) {
                $query->orderBy($column);
            }

            /** @var list<Model> $rows */
            $rows = $query->get(['id', 'name', 'sort_order'])->all();
            $byId = [];

            foreach ($rows as $row) {
                $byId[(int) $row->getKey()] = $row;
            }

            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, array_keys($byId)) !== []) {
                throw ValidationException::withMessages(['ids' => 'Список изменился. Обновите страницу и повторите перестановку.']);
            }

            $moved = array_flip($ids);
            $queue = $ids;
            $before = [];
            $ordered = [];

            foreach ($rows as $row) {
                $id = (int) $row->getKey();

                if (isset($moved[$id])) {
                    $before[] = $row->getAttribute('name');
                    $ordered[] = $byId[array_shift($queue)];
                } else {
                    $ordered[] = $row;
                }
            }

            $after = array_map(fn (Model $row) => $row->getAttribute('name'), array_map(fn (int $id) => $byId[$id], $ids));

            foreach ($ordered as $position => $row) {
                $sortOrder = ($position + 1) * self::STEP;

                if ((int) $row->getAttribute('sort_order') !== $sortOrder) {
                    $class::query()->whereKey($row->getKey())->update(['sort_order' => $sortOrder]);
                }
            }

            if ($before !== $after) {
                $this->audit->record($auditSubject.'.reordered', $auditSubject, null, $label, 'Изменён порядок значений', [
                    'order' => [implode(', ', $before), implode(', ', $after)],
                ]);
            }
        });
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function next(string $class): int
    {
        return (int) $class::query()->max('sort_order') + self::STEP;
    }
}
