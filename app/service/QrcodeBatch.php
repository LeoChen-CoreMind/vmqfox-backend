<?php

namespace app\service;

use InvalidArgumentException;

final class QrcodeBatch
{
    public const MAX_ITEMS = 20;

    /**
     * @return array<int,array{client_id:string,pay_url:string,price:string}>
     */
    public static function normalizeItems(mixed $items): array
    {
        if (!is_array($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('每批必须包含 1 到 20 个二维码');
        }

        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('二维码内容或金额无效');
            }

            $clientId = trim((string) ($item['client_id'] ?? ''));
            $payUrl = trim((string) ($item['pay_url'] ?? ''));
            $price = QrcodeInput::normalizePrice($item['price'] ?? null);
            if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $clientId) || isset($seen[$clientId])) {
                throw new InvalidArgumentException('二维码客户端编号无效或重复');
            }
            if ($payUrl === '' || strlen($payUrl) > 255 || $price === null) {
                throw new InvalidArgumentException('二维码内容或金额无效');
            }

            $seen[$clientId] = true;
            $normalized[] = ['client_id' => $clientId, 'pay_url' => $payUrl, 'price' => $price];
        }

        return $normalized;
    }

    /**
     * @param array<int,array{client_id:string,pay_url:string,price:string}> $items
     * @param array<int,array<string,mixed>> $existingRows
     * @return array{items:array<int,array{client_id:string,pay_url:string,price:string,existing_id:?string}>,database_conflicts:array<int,array{client_id:string,price:string,existing_id:string}>,batch_conflicts:array<int,array{price:string,client_ids:array<int,string>}>,has_conflicts:bool,conflict_token:string}
     */
    public static function preview(int $type, array $items, array $existingRows): array
    {
        if (QrcodeInput::normalizeType($type) === null) {
            throw new InvalidArgumentException('支付类型错误');
        }
        $items = self::normalizeItems($items);

        $existingByPrice = [];
        foreach ($existingRows as $row) {
            if (!is_array($row) || (int) ($row['type'] ?? 0) !== $type) {
                continue;
            }

            $price = QrcodeInput::normalizePrice($row['price'] ?? null);
            $id = self::normalizeDecimalId($row['id'] ?? null);
            if ($price === null || $id === null || isset($existingByPrice[$price])) {
                continue;
            }

            $existingByPrice[$price] = $id;
        }

        $previewItems = [];
        $databaseConflicts = [];
        $clientIdsByPrice = [];
        $priceOrder = [];
        foreach ($items as $item) {
            $existingId = $existingByPrice[$item['price']] ?? null;
            $previewItem = [
                'client_id' => $item['client_id'],
                'pay_url' => $item['pay_url'],
                'price' => $item['price'],
                'existing_id' => $existingId,
            ];
            $previewItems[] = $previewItem;

            if ($existingId !== null) {
                $databaseConflicts[] = [
                    'client_id' => $item['client_id'],
                    'price' => $item['price'],
                    'existing_id' => $existingId,
                ];
            }

            if (!isset($clientIdsByPrice[$item['price']])) {
                $clientIdsByPrice[$item['price']] = [];
                $priceOrder[] = $item['price'];
            }
            $clientIdsByPrice[$item['price']][] = $item['client_id'];
        }

        $batchConflicts = [];
        foreach ($priceOrder as $price) {
            if (count($clientIdsByPrice[$price]) > 1) {
                $batchConflicts[] = [
                    'price' => $price,
                    'client_ids' => $clientIdsByPrice[$price],
                ];
            }
        }

        $tokenPayload = [
            'type' => $type,
            'items' => array_map(fn (array $item): array => [$item['client_id'], $item['price']], $previewItems),
            'existing' => array_map(fn (array $item): array => [$item['client_id'], $item['existing_id']], $previewItems),
        ];

        return [
            'items' => $previewItems,
            'database_conflicts' => $databaseConflicts,
            'batch_conflicts' => $batchConflicts,
            'has_conflicts' => $databaseConflicts !== [] || $batchConflicts !== [],
            'conflict_token' => hash('sha256', json_encode($tokenPayload, JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param array<int,array{client_id:string,pay_url:string,price:string}> $items
     * @return array<string,array{action:string,target_id:?int}>
     */
    public static function normalizeDecisions(mixed $decisions, array $items): array
    {
        if (!is_array($decisions)) {
            throw new InvalidArgumentException('每个二维码都必须提交处理决定');
        }

        if (array_is_list($decisions)) {
            $byClientId = [];
            foreach ($decisions as $decision) {
                if (!is_array($decision)) {
                    throw new InvalidArgumentException('二维码处理决定无效');
                }
                $clientId = trim((string) ($decision['client_id'] ?? ''));
                if ($clientId === '' || isset($byClientId[$clientId])) {
                    throw new InvalidArgumentException('处理决定的客户端编号无效或重复');
                }
                unset($decision['client_id']);
                $byClientId[$clientId] = $decision;
            }
            $decisions = $byClientId;
        }

        if (count($decisions) !== count($items)) {
            throw new InvalidArgumentException('二维码处理决定不完整');
        }

        $normalized = [];
        foreach ($items as $item) {
            $clientId = $item['client_id'];
            if (!array_key_exists($clientId, $decisions) || !is_array($decisions[$clientId])) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }

            $decision = $decisions[$clientId];
            $action = $decision['action'] ?? null;
            if (!is_string($action) || !in_array($action, ['insert', 'replace', 'skip'], true)) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }

            $targetId = null;
            if ($action === 'replace') {
                $targetId = QrcodeInput::normalizeId($decision['target_id'] ?? null);
                if ($targetId === null) {
                    throw new InvalidArgumentException('替换操作必须指定有效的二维码编号');
                }
            } elseif (array_key_exists('target_id', $decision) && $decision['target_id'] !== null) {
                throw new InvalidArgumentException('非替换操作不能指定目标二维码');
            }

            $normalized[$clientId] = ['action' => $action, 'target_id' => $targetId];
        }

        foreach (array_keys($decisions) as $clientId) {
            if (!isset($normalized[$clientId])) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }
        }

        return $normalized;
    }

    /**
     * @param array{items:array<int,array{client_id:string,pay_url:string,price:string,existing_id:?string}>} $preview
     * @param array<string,array{action:string,target_id:?int}> $decisions
     * @return array<int,array{client_id:string,action:string,target_id:?int,pay_url:string,price:string}>
     */
    public static function commitPlan(array $preview, array $decisions): array
    {
        if (!isset($preview['items']) || !is_array($preview['items'])) {
            throw new InvalidArgumentException('二维码预览数据无效');
        }

        $plan = [];
        $insertsByPrice = [];
        foreach ($preview['items'] as $item) {
            if (!is_array($item) || !isset($item['client_id'], $item['pay_url'], $item['price'])) {
                throw new InvalidArgumentException('二维码预览数据无效');
            }

            $clientId = $item['client_id'];
            if (!isset($decisions[$clientId]) || !is_array($decisions[$clientId])) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }

            $decision = $decisions[$clientId];
            $action = $decision['action'] ?? null;
            $existingId = $item['existing_id'] ?? null;
            $targetId = $decision['target_id'] ?? null;

            if (!in_array($action, ['insert', 'replace', 'skip'], true)) {
                throw new InvalidArgumentException('二维码处理决定无效');
            }
            if ($action === 'insert') {
                if ($existingId !== null) {
                    throw new InvalidArgumentException('该金额已存在二维码，不能新增');
                }
                $insertsByPrice[$item['price']] = ($insertsByPrice[$item['price']] ?? 0) + 1;
                if ($insertsByPrice[$item['price']] > 1) {
                    throw new InvalidArgumentException('同一金额只能新增一个二维码');
                }
                $targetId = null;
            } elseif ($action === 'replace') {
                if ($existingId === null || !is_int($targetId) || (string) $targetId !== $existingId) {
                    throw new InvalidArgumentException('替换目标与预览冲突不匹配');
                }
            } else {
                if ($targetId !== null) {
                    throw new InvalidArgumentException('跳过操作不能指定目标二维码');
                }
            }

            $plan[] = [
                'client_id' => $clientId,
                'action' => $action,
                'target_id' => $targetId,
                'pay_url' => $item['pay_url'],
                'price' => $item['price'],
            ];
        }

        if (count($decisions) !== count($plan)) {
            throw new InvalidArgumentException('二维码处理决定不完整');
        }

        return $plan;
    }

    private static function normalizeDecimalId(mixed $id): ?string
    {
        $id = trim((string) $id);
        return preg_match('/^[1-9]\d*$/', $id) ? $id : null;
    }
}
