<?php

namespace app\service;

class QrcodeInput
{
    private const SORT_ORDERS = [
        'newest' => ['id' => 'desc'],
        'oldest' => ['id' => 'asc'],
        'amount_asc' => ['price' => 'asc', 'id' => 'asc'],
        'amount_desc' => ['price' => 'desc', 'id' => 'desc'],
        'enabled_first' => ['state' => 'asc', 'id' => 'desc'],
        'disabled_first' => ['state' => 'desc', 'id' => 'desc'],
    ];

    /**
     * @return array{page:int,limit:int}
     */
    public static function pagination(mixed $page, mixed $limit): array
    {
        $page = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($limit, FILTER_VALIDATE_INT);

        return [
            'page' => $page === false ? 1 : $page,
            'limit' => in_array($limit, [12, 24, 48], true) ? $limit : 12,
        ];
    }

    public static function normalizePrice(mixed $price): ?string
    {
        $price = trim((string) $price);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
            return null;
        }

        $value = (float) $price;
        if ($value <= 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    public static function normalizeId(mixed $id): ?int
    {
        $id = trim((string) $id);
        if (!preg_match('/^[1-9]\d*$/', $id)) {
            return null;
        }

        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : $value;
    }

    public static function normalizeState(mixed $state): ?int
    {
        $state = trim((string) $state);
        return in_array($state, ['0', '1'], true) ? (int) $state : null;
    }

    public static function normalizeType(mixed $type): ?int
    {
        $type = filter_var($type, FILTER_VALIDATE_INT);
        return in_array($type, [1, 2], true) ? $type : null;
    }

    public static function normalizeSort(mixed $sort): string
    {
        $sort = trim((string) $sort);
        return isset(self::SORT_ORDERS[$sort]) ? $sort : 'newest';
    }

    /**
     * @return array<string,string>
     */
    public static function sortOrder(string $sort): array
    {
        return self::SORT_ORDERS[self::normalizeSort($sort)];
    }
}
