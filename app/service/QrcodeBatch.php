<?php

namespace app\service;

use InvalidArgumentException;

final class QrcodeBatch
{
    public const MAX_ITEMS = 20;

    public static function normalizeItems(mixed $items): array
    {
        if (!is_array($items) || !$items || count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('每批必须包含 1 到 20 个二维码');
        }
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('二维码数据无效');
            $id = (string) ($item['client_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException('二维码客户端编号无效或重复');
            }
            $seen[$id] = true;
        }
        return array_values($items);
    }

    public static function preview(int $type, array $items, array $existingRows): array
    {
        $items = self::normalizeItems($items);
        $normalized = [];
        $conflicts = [];
        foreach ($items as $item) {
            $row = QrcodeCatalog::normalize($item, $type);
            $row['client_id'] = $item['client_id'];
            $row['existing_id'] = null;
            foreach ($existingRows as $existing) {
                if (QrcodeCatalog::duplicates($row, $existing)) {
                    $row['existing_id'] = (string) $existing['id'];
                    $conflicts[] = ['client_id' => $row['client_id'], 'existing_id' => $row['existing_id'], 'price' => $row['price']];
                    break;
                }
            }
            $normalized[] = $row;
        }
        return ['items' => $normalized, 'database_conflicts' => $conflicts, 'batch_conflicts' => [],
            'has_conflicts' => $conflicts !== [], 'existing_rows' => $existingRows,
            'conflict_token' => hash('sha256', json_encode([$type, $normalized, $existingRows], JSON_THROW_ON_ERROR))];
    }

    public static function normalizeDecisions(mixed $decisions, array $items): array
    {
        if (!is_array($decisions)) throw new InvalidArgumentException('二维码处理决定不完整');
        if (array_is_list($decisions)) {
            $mapped = [];
            foreach ($decisions as $decision) {
                if (!is_array($decision)) throw new InvalidArgumentException('处理决定无效');
                $id = $decision['client_id'] ?? '';
                if (!$id || isset($mapped[$id])) throw new InvalidArgumentException('客户端编号重复或无效');
                $mapped[$id] = $decision;
            }
            $decisions = $mapped;
        }
        if (count($decisions) !== count($items)) throw new InvalidArgumentException('二维码处理决定不完整');
        foreach ($items as $item) {
            $decision = $decisions[$item['client_id']] ?? null;
            if (!is_array($decision) || !in_array($decision['action'] ?? '', ['insert', 'replace', 'skip'], true)) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }
            if ($decision['action'] === 'replace' && QrcodeInput::normalizeId($decision['target_id'] ?? null) === null) {
                throw new InvalidArgumentException('替换操作必须指定有效的二维码编号');
            }
            if ($decision['action'] !== 'replace' && isset($decision['target_id'])) {
                throw new InvalidArgumentException('非替换操作不能指定目标二维码');
            }
        }
        return $decisions;
    }

    public static function commitPlan(array $preview, array $decisions): array
    {
        $rows = $preview['existing_rows'];
        $plan = [];
        $targets = [];
        foreach ($preview['items'] as $item) {
            $decision = $decisions[$item['client_id']];
            $action = $decision['action'];
            $target = $action === 'replace' ? (int) $decision['target_id'] : null;
            if ($action === 'replace') {
                if ((string) $target !== $item['existing_id'] || isset($targets[$target])) {
                    throw new InvalidArgumentException('替换目标与预览不符或被重复替换');
                }
                $targets[$target] = true;
                $rows = array_values(array_filter($rows, static fn ($row) => (int) ($row['id'] ?? 0) !== $target));
            }
            if ($action !== 'skip') $rows[] = $item;
            $plan[] = array_merge($item, ['action' => $action, 'target_id' => $target]);
        }
        QrcodeCatalog::validateRows($rows);
        return $plan;
    }
}
