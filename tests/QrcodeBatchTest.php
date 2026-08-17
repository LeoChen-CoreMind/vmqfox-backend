<?php

use app\service\QrcodeBatch;

test('builds database and upload-only conflict groups', function (): void {
    $items = QrcodeBatch::normalizeItems([
        ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
        ['client_id' => 'local-2', 'pay_url' => 'wxp://two', 'price' => '10.00'],
        ['client_id' => 'local-3', 'pay_url' => 'wxp://three', 'price' => '20'],
    ]);
    $preview = QrcodeBatch::preview(1, $items, [
        ['id' => 88, 'type' => 1, 'pay_url' => 'wxp://old', 'price' => '20.00', 'state' => 1],
        ['id' => 99, 'type' => 2, 'pay_url' => 'alipays://ignored', 'price' => '10.00', 'state' => 0],
    ]);

    assertSameValue([['price' => '10.00', 'client_ids' => ['local-1', 'local-2']]], $preview['batch_conflicts']);
    assertSameValue('88', $preview['items'][2]['existing_id']);
    assertSameValue(true, $preview['has_conflicts']);
});

test('preview validates payment type and normalizes raw item prices', function (): void {
    try {
        QrcodeBatch::preview(3, [
            ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
        ], []);
    } catch (InvalidArgumentException) {
        $preview = QrcodeBatch::preview(1, [
            ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
            ['client_id' => 'local-2', 'pay_url' => 'wxp://two', 'price' => '10.00'],
        ], [
            ['id' => 7, 'type' => 1, 'pay_url' => 'wxp://existing', 'price' => '10', 'state' => 0],
        ]);

        assertSameValue([['price' => '10.00', 'client_ids' => ['local-1', 'local-2']]], $preview['batch_conflicts']);
        assertSameValue('7', $preview['items'][0]['existing_id']);
        assertSameValue('7', $preview['items'][1]['existing_id']);
        assertSameValue(['10.00', '10.00'], array_column($preview['items'], 'price'));
        return;
    }

    throw new RuntimeException('expected invalid payment type to be rejected');
});

test('rejects stale conflict tokens and illegal replacement targets', function (): void {
    $items = QrcodeBatch::normalizeItems([
        ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
    ]);
    $before = QrcodeBatch::preview(1, $items, []);
    $after = QrcodeBatch::preview(1, $items, [
        ['id' => 7, 'type' => 1, 'pay_url' => 'wxp://existing', 'price' => '10', 'state' => 0],
    ]);

    assertSameValue(false, hash_equals($before['conflict_token'], $after['conflict_token']));
});

test('rejects invalid batch item collections and fields', function (): void {
    $invalid = [
        [],
        array_fill(0, 21, ['client_id' => 'x', 'pay_url' => 'wxp://x', 'price' => '1']),
        [['client_id' => 'bad id', 'pay_url' => 'wxp://x', 'price' => '1']],
        [['client_id' => 'duplicate', 'pay_url' => 'wxp://x', 'price' => '1'], ['client_id' => 'duplicate', 'pay_url' => 'wxp://y', 'price' => '2']],
        [['client_id' => 'empty-url', 'pay_url' => '', 'price' => '1']],
        [['client_id' => 'long-url', 'pay_url' => str_repeat('x', 256), 'price' => '1']],
        [['client_id' => 'bad-price', 'pay_url' => 'wxp://x', 'price' => '0']],
        'not-an-array',
    ];

    foreach ($invalid as $items) {
        try {
            QrcodeBatch::normalizeItems($items);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === '') {
                throw new RuntimeException('expected a user-readable message');
            }
            continue;
        }
        throw new RuntimeException('expected InvalidArgumentException');
    }
});

test('normalizes decisions and commits a validated plan', function (): void {
    $items = QrcodeBatch::normalizeItems([
        ['client_id' => 'insert-1', 'pay_url' => 'wxp://one', 'price' => '10'],
        ['client_id' => 'replace-1', 'pay_url' => 'wxp://two', 'price' => '20'],
        ['client_id' => 'skip-1', 'pay_url' => 'wxp://three', 'price' => '30'],
    ]);
    $preview = QrcodeBatch::preview(1, $items, [
        ['id' => 8, 'type' => 1, 'pay_url' => 'wxp://old', 'price' => '20', 'state' => 1],
        ['id' => 9, 'type' => 1, 'pay_url' => 'wxp://old2', 'price' => '30', 'state' => 1],
    ]);
    $decisions = QrcodeBatch::normalizeDecisions([
        'insert-1' => ['action' => 'insert'],
        'replace-1' => ['action' => 'replace', 'target_id' => 8],
        'skip-1' => ['action' => 'skip'],
    ], $items);
    assertSameValue($decisions, QrcodeBatch::normalizeDecisions([
        ['client_id' => 'insert-1', 'action' => 'insert'],
        ['client_id' => 'replace-1', 'action' => 'replace', 'target_id' => 8],
        ['client_id' => 'skip-1', 'action' => 'skip'],
    ], $items));
    assertSameValue([
        ['client_id' => 'insert-1', 'action' => 'insert', 'target_id' => null, 'pay_url' => 'wxp://one', 'price' => '10.00'],
        ['client_id' => 'replace-1', 'action' => 'replace', 'target_id' => 8, 'pay_url' => 'wxp://two', 'price' => '20.00'],
        ['client_id' => 'skip-1', 'action' => 'skip', 'target_id' => null, 'pay_url' => 'wxp://three', 'price' => '30.00'],
    ], QrcodeBatch::commitPlan($preview, $decisions));

    foreach ([
        ['insert-1' => ['action' => 'insert']],
        ['insert-1' => ['action' => 'insert'], 'replace-1' => ['action' => 'replace', 'target_id' => 8], 'skip-1' => ['action' => 'skip'], 'unknown' => ['action' => 'skip']],
        ['insert-1' => ['action' => 'insert'], 'replace-1' => ['action' => 'replace'], 'skip-1' => ['action' => 'skip']],
    ] as $invalid) {
        try {
            QrcodeBatch::normalizeDecisions($invalid, $items);
        } catch (InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('expected invalid decisions to be rejected');
    }

    $illegalTarget = $decisions;
    $illegalTarget['replace-1']['target_id'] = 7;
    try {
        QrcodeBatch::commitPlan($preview, $illegalTarget);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('expected an illegal replacement target to be rejected');
});
