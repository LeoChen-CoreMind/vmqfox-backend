# QR Code Batch Upload and Sorting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a 20-image additive recognition queue, conflict-aware transactional batch saving, and server-side QR list sorting for both WeChat and Alipay.

**Architecture:** Keep request normalization and conflict planning in pure PHP services so duplicate and stale-state rules are unit-testable without a database. The authenticated controller owns database reads, a shared `setting.vkey = user` row lock, and the commit transaction; the browser owns upload queue scheduling and explicit conflict decisions. List sorting uses fixed server tokens mapped to known SQL order clauses before pagination.

**Tech Stack:** PHP 8.2, ThinkPHP 6 database/query APIs, browser JavaScript (ES5-compatible UMD modules), Layui layer dialogs, Node.js built-in test runner, project PHP test harness.

## Global Constraints

- WeChat type `1` and Alipay type `2` compare duplicates only within their own type.
- Normalize every price to exactly two decimal places before comparison.
- Append selections, ignore exact duplicate files by `name + size + lastModified`, and hold at most 20 queue items.
- Run at most two recognition jobs concurrently, including files appended while workers are active.
- Queue states are exactly `queued`, `processing`, `ready`, `error`, `skipped`, and `saved`.
- Preserve automatic QR/amount recognition and allow both fields to be edited manually.
- Treat equal amounts inside the selected batch as conflicts even if no database row exists.
- Conflict choices are `Replace all`, `Review individually`, and `Skip all conflicts`.
- For batch-only conflicts, selection order determines the current winner; `Replace all` makes the last selected item win.
- Replacing a database conflict updates only `pay_url`; existing `id`, `price`, and `state` remain unchanged.
- Preview performs no writes; commit performs all writes in one transaction after locking a stable `setting` row and rechecking conflicts.
- Legacy single-add and amount-update endpoints use the same lock and reject same-type duplicate amounts.
- Sort in SQL before `page()` using only whitelisted tokens and a stable `id` tie-breaker.
- WeChat and Alipay persist sort choices under separate localStorage keys.
- Keep all existing REST routes and single-upload/manual-edit behavior compatible.

## File Structure

- Create `app/service/QrcodeBatch.php`: normalize batch items and decisions, build conflict previews/tokens, and validate commit plans without database access.
- Create `app/service/QrcodeConflictChanged.php`: carry a refreshed conflict preview out of a rolled-back commit transaction.
- Modify `app/service/QrcodeInput.php`: normalize QR types and sort tokens; return fixed SQL order maps.
- Modify `app/controller/api/Qrcode.php`: expose preview/commit actions, apply server-side sorting, share the write lock, and make legacy writes duplicate-safe.
- Modify `route/app.php`: register authenticated batch preview and commit routes before the dynamic `qrcode/:id` routes.
- Modify `public/js/qrcode-admin.js`: implement additive queue state, bounded recognition scheduling, preview/conflict decisions, and transactional batch commit UI.
- Modify `public/js/qrcode-list.js`: render sort controls, persist sort per payment type, and include sort in paginated list requests.
- Modify `public/css/qrcode-admin.css`: style compact upload statuses, conflict dialogs, sort controls, and responsive toolbar behavior.
- Modify `public/aaa.html`: bump QR asset cache keys after browser code/CSS changes.
- Create `tests/QrcodeBatchTest.php`: unit-test normalization, payment-type isolation inputs, conflict groups, decisions, and stale tokens.
- Modify `tests/QrcodeInputTest.php`: unit-test type and sorting whitelist behavior.
- Create `tests/QrcodeControllerContractTest.php`: verify routes, shared lock use, transaction boundary, replace-in-place fields, and sort-before-pagination structure.
- Modify `tests/js/qrcode-admin.test.js`: test append/dedupe/cap/scheduler and all conflict decision modes.
- Modify `tests/js/qrcode-list.test.js`: test sort request construction, persistence keys, and default fallback.
- Modify `README.md`: update the current version, API table, feature list, and dated release notes for the batch workflow and server-side sorting.
- Modify `ver`: change the application release marker from `2.3.3|2026-08-17` to `2.3.4|2026-08-17`.

---

### Task 1: Backend Input and Conflict Planning

**Files:**
- Create: `app/service/QrcodeBatch.php`
- Modify: `app/service/QrcodeInput.php`
- Create: `tests/QrcodeBatchTest.php`
- Modify: `tests/QrcodeInputTest.php`

**Interfaces:**
- Produces: `QrcodeInput::normalizeType(mixed $type): ?int`
- Produces: `QrcodeInput::normalizeSort(mixed $sort): string`
- Produces: `QrcodeInput::sortOrder(string $sort): array<string,string>`
- Produces: `QrcodeBatch::normalizeItems(mixed $items): array<int,array{client_id:string,pay_url:string,price:string}>`
- Produces: `QrcodeBatch::preview(int $type, array $items, array $existingRows): array`
- Produces: `QrcodeBatch::normalizeDecisions(mixed $decisions, array $items): array<string,array{action:string,target_id:?int}>`
- Produces: `QrcodeBatch::commitPlan(array $preview, array $decisions): array<int,array{client_id:string,action:string,target_id:?int,pay_url:string,price:string}>`

- [ ] **Step 1: Write failing input and sort tests**

Add exact whitelist expectations to `tests/QrcodeInputTest.php`:

```php
test('normalizes QR type and fixed sort tokens', function (): void {
    assertSameValue(1, QrcodeInput::normalizeType('1'));
    assertSameValue(2, QrcodeInput::normalizeType(2));
    assertSameValue(null, QrcodeInput::normalizeType('3'));
    assertSameValue('newest', QrcodeInput::normalizeSort('price desc, sleep(5)'));
    assertSameValue('amount_asc', QrcodeInput::normalizeSort('amount_asc'));
    assertSameValue(['state' => 'desc', 'id' => 'desc'], QrcodeInput::sortOrder('disabled_first'));
});
```

- [ ] **Step 2: Run the focused PHP test and verify failure**

Run: `php tests/run.php`

Expected: FAIL because `normalizeType()`, `normalizeSort()`, and `sortOrder()` do not exist.

- [ ] **Step 3: Implement fixed input normalization**

Add these maps and methods to `QrcodeInput`:

```php
private const SORT_ORDERS = [
    'newest' => ['id' => 'desc'],
    'oldest' => ['id' => 'asc'],
    'amount_asc' => ['price' => 'asc', 'id' => 'asc'],
    'amount_desc' => ['price' => 'desc', 'id' => 'desc'],
    'enabled_first' => ['state' => 'asc', 'id' => 'desc'],
    'disabled_first' => ['state' => 'desc', 'id' => 'desc'],
];

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

public static function sortOrder(string $sort): array
{
    return self::SORT_ORDERS[self::normalizeSort($sort)];
}
```

- [ ] **Step 4: Write failing batch planning tests**

Create `tests/QrcodeBatchTest.php` with concrete cases for invalid type-independent items, 21-item rejection, duplicate `client_id`, same-price grouping, same-type database matches supplied by the caller, decisions, and stale tokens:

```php
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
```

Also assert that `normalizeItems()` rejects empty/over-255-byte URLs, invalid amounts, invalid/duplicate client IDs, non-arrays, and more than 20 items by throwing `InvalidArgumentException` with a user-readable message.

- [ ] **Step 5: Run the PHP suite and verify batch tests fail**

Run: `php tests/run.php`

Expected: FAIL because `app\service\QrcodeBatch` does not exist.

- [ ] **Step 6: Implement the pure batch service**

Use a fixed client-ID grammar and deterministic conflict token:

```php
final class QrcodeBatch
{
    public const MAX_ITEMS = 20;

    public static function normalizeItems(mixed $items): array
    {
        if (!is_array($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('每批必须包含 1 到 20 个二维码');
        }

        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            $clientId = trim((string) ($item['client_id'] ?? ''));
            $payUrl = trim((string) ($item['pay_url'] ?? ''));
            $price = QrcodeInput::normalizePrice($item['price'] ?? null);
            if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $clientId) || isset($seen[$clientId])) {
                throw new \InvalidArgumentException('二维码客户端编号无效或重复');
            }
            if ($payUrl === '' || strlen($payUrl) > 255 || $price === null) {
                throw new \InvalidArgumentException('二维码内容或金额无效');
            }
            $seen[$clientId] = true;
            $normalized[] = ['client_id' => $clientId, 'pay_url' => $payUrl, 'price' => $price];
        }
        return $normalized;
    }
}
```

`preview()` must discard rows whose `type` differs from `$type`, normalize row prices, represent IDs as decimal strings, create `database_conflicts` and `batch_conflicts` in item selection order, and calculate:

```php
$tokenPayload = [
    'type' => $type,
    'items' => array_map(fn ($item) => [$item['client_id'], $item['price']], $previewItems),
    'existing' => array_map(fn ($item) => [$item['client_id'], $item['existing_id']], $previewItems),
];
$conflictToken = hash('sha256', json_encode($tokenPayload, JSON_UNESCAPED_SLASHES));
```

`normalizeDecisions()` accepts exactly one decision per item, allows only `insert`, `replace`, or `skip`, requires a positive `target_id` only for replace, and rejects unknown/missing client IDs. `commitPlan()` validates that insert has no existing row, replace targets the preview's same-price `existing_id`, and each price ends with no more than one insert; it returns work in original item order.

- [ ] **Step 7: Run focused and full PHP tests**

Run: `php tests/run.php`

Expected: all PHP tests PASS.

- [ ] **Step 8: Commit backend rules**

```bash
git add app/service/QrcodeBatch.php app/service/QrcodeInput.php tests/QrcodeBatchTest.php tests/QrcodeInputTest.php
git commit -m "feat: add QR batch conflict planning"
```

### Task 2: Transactional Batch API and Shared Write Lock

**Files:**
- Modify: `app/controller/api/Qrcode.php`
- Create: `app/service/QrcodeConflictChanged.php`
- Modify: `route/app.php`
- Create: `tests/QrcodeControllerContractTest.php`

**Interfaces:**
- Consumes: all `QrcodeBatch` and `QrcodeInput` methods from Task 1.
- Produces: `POST index.php/api/qrcode/batch/preview`.
- Produces: `POST index.php/api/qrcode/batch/commit`.
- Produces: `Qrcode::withQrWriteLock(callable $callback): mixed` as the one locking path used by batch commit, single create, and amount update.

- [ ] **Step 1: Write failing route and controller contract tests**

Create source-contract tests which lock down the security-sensitive structure without requiring a developer database:

```php
test('batch routes are authenticated and registered before dynamic QR routes', function (): void {
    $routes = file_get_contents(dirname(__DIR__) . '/route/app.php');
    $preview = strpos($routes, "Route::post('qrcode/batch/preview'");
    $commit = strpos($routes, "Route::post('qrcode/batch/commit'");
    $dynamic = strpos($routes, "Route::post('qrcode/:id/amount'");
    assertSameValue(true, $preview !== false && $commit !== false);
    assertSameValue(true, $preview < $dynamic && $commit < $dynamic);
});

test('all QR amount writes share one row lock and batch commit is transactional', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Qrcode.php');
    assertSameValue(true, substr_count($source, 'withQrWriteLock(') >= 4);
    assertSameValue(true, str_contains($source, "where('vkey', 'user')->lock(true)->find()"));
    assertSameValue(true, str_contains($source, 'Db::transaction(function'));
    assertSameValue(true, str_contains($source, "->update(['pay_url' => \$item['pay_url']])"));
});
```

Add assertions that list ordering is called before `page()`, and that replacement does not update `price`, `state`, or `id`.

- [ ] **Step 2: Run the PHP suite and verify contract failure**

Run: `php tests/run.php`

Expected: FAIL because batch routes, transaction, and shared lock do not exist.

- [ ] **Step 3: Register authenticated batch routes**

Insert these before any `qrcode/:id` route:

```php
Route::post('qrcode/batch/preview', 'api/Qrcode/batchPreview');
Route::post('qrcode/batch/commit', 'api/Qrcode/batchCommit');
```

- [ ] **Step 4: Implement preview with same-type database lookup**

Read JSON/form request fields through `Request::param()`, validate type and items, then fetch only matching type and normalized prices:

```php
public function batchPreview()
{
    try {
        $type = QrcodeInput::normalizeType(Request::param('type'));
        if ($type === null) {
            throw new \InvalidArgumentException('支付类型错误');
        }
        $items = QrcodeBatch::normalizeItems(Request::param('items'));
        $rows = $this->findExistingByPrices($type, array_column($items, 'price'));
        return $this->success(QrcodeBatch::preview($type, $items, $rows));
    } catch (\InvalidArgumentException $e) {
        return $this->error($e->getMessage());
    }
}
```

`findExistingByPrices()` must query `pay_qrcode` with both `type = $type` and `price IN (...)`; never accept record IDs or SQL fragments from the request as lookup scope.

- [ ] **Step 5: Implement commit transaction and stale-state response**

Use one transaction and one shared row lock:

```php
$result = Db::transaction(function () use ($type, $items, $decisions, $submittedToken) {
    return $this->withQrWriteLock(function () use ($type, $items, $decisions, $submittedToken) {
        $fresh = QrcodeBatch::preview(
            $type,
            $items,
            $this->findExistingByPrices($type, array_column($items, 'price'))
        );
        if (!hash_equals($fresh['conflict_token'], $submittedToken)) {
            throw new QrcodeConflictChanged($fresh);
        }
        $plan = QrcodeBatch::commitPlan($fresh, $decisions);
        return $this->applyBatchPlan($type, $plan);
    });
});
```

Create `app/service/QrcodeConflictChanged.php` as a final exception with `__construct(private array $preview)`, `preview(): array`, and parent message `二维码冲突状态已变化，请重新确认`. Catch it outside `Db::transaction()` and return response code `409` with `data.preview` containing the exception preview. Do not perform any insert/update before token comparison and full plan validation.

`applyBatchPlan()` must initialize per-item results and totals `{inserted: 0, replaced: 0, skipped: 0, failed: 0}`. For `replace`, update with all three predicates (`id`, `type`, normalized `price`) and only this field:

```php
Db::name('pay_qrcode')
    ->where('id', $item['target_id'])
    ->where('type', $type)
    ->where('price', $item['price'])
    ->update(['pay_url' => $item['pay_url']]);
```

For `insert`, insert `type`, `pay_url`, normalized `price`, and `state => 0`. Any unexpected write count throws so ThinkPHP rolls back every write.

- [ ] **Step 6: Route legacy writes through the same lock**

Implement one helper:

```php
private function withQrWriteLock(callable $callback): mixed
{
    $lock = Db::name('setting')->where('vkey', 'user')->lock(true)->find();
    if (!$lock) {
        throw new \RuntimeException('系统设置锁记录不存在');
    }
    return $callback();
}
```

Wrap `createForType()` and `updateAmount()` in `Db::transaction()` plus `withQrWriteLock()`. Under the lock, reject another `pay_qrcode` row with the same `type` and normalized `price`; amount update must exclude its current ID. Return a clear duplicate-amount error and preserve existing endpoint response shapes on success.

- [ ] **Step 7: Apply sort before API pagination**

In `paginatedList()` normalize `Request::param('sort', 'newest')`, then call the fixed order map before paging:

```php
$sort = QrcodeInput::normalizeSort(Request::param('sort', 'newest'));
$items = $query
    ->order(QrcodeInput::sortOrder($sort))
    ->page($pagination['page'], $pagination['limit'])
    ->select()
    ->toArray();
```

Return the normalized `sort` token with `total`, `items`, `page`, and `limit`.

- [ ] **Step 8: Run the PHP suite**

Run: `php tests/run.php`

Expected: all PHP tests PASS, including route/security compatibility tests.

- [ ] **Step 9: Commit the API work**

```bash
git add app/controller/api/Qrcode.php app/service/QrcodeBatch.php app/service/QrcodeConflictChanged.php route/app.php tests/QrcodeControllerContractTest.php
git commit -m "feat: add transactional QR batch API"
```

### Task 3: Additive Upload Queue and Stable Recognition Scheduler

**Files:**
- Modify: `public/js/qrcode-admin.js`
- Modify: `tests/js/qrcode-admin.test.js`

**Interfaces:**
- Produces: `fileIdentity(file): string`.
- Produces: `appendFiles(items, files, createItem, maxItems): {items:Array, added:Array, ignored:number, rejected:number}`.
- Produces: `createRecognitionQueue(worker, concurrency, onChange)` with `enqueue(items)`, `remove(id)`, `whenIdle()`, and no more than two active workers.
- Preserves: `QrAdmin.mount === QrAdmin.mountUpload`, `analyzeFiles()`, `runPool()`, and the existing setting-page callers.

- [ ] **Step 1: Write failing pure queue tests**

Add tests that do not require a browser DOM:

```js
test('appends selections, ignores exact files, and enforces total cap', () => {
    const make = (name, size, lastModified) => ({name, size, lastModified});
    const initial = [{id: 'local-1', file: make('a.jpg', 10, 1)}];
    const files = [make('a.jpg', 10, 1)].concat(
        Array.from({length: 21}, (_, index) => make('n' + index + '.jpg', index + 1, index + 2))
    );
    const result = QrAdmin.appendFiles(initial, files, (file, index) => ({id: 'new-' + index, file}), 20);
    assert.equal(result.items.length, 20);
    assert.equal(result.ignored, 1);
    assert.equal(result.rejected, 2);
});
```

Add an asynchronous scheduler case: enqueue two files, wait until both start, append two more, remove one pending item, resolve workers, and assert maximum active count is two and no callback changes the removed item.

- [ ] **Step 2: Run JS tests and verify failure**

Run: `npm test`

Expected: FAIL because `appendFiles()` and `createRecognitionQueue()` are not exported.

- [ ] **Step 3: Implement file identity and append logic**

Use exact metadata identity and never clear previous items:

```js
function fileIdentity(file) {
    return [file.name, Number(file.size) || 0, Number(file.lastModified) || 0].join('\u0000');
}

function appendFiles(items, files, createItem, maxItems) {
    var result = items.slice();
    var existing = {};
    result.forEach(function (item) { existing[fileIdentity(item.file)] = true; });
    var added = [], ignored = 0, rejected = 0;
    Array.prototype.forEach.call(files || [], function (file, index) {
        var key = fileIdentity(file);
        if (existing[key]) { ignored++; return; }
        if (result.length >= maxItems) { rejected++; return; }
        var item = createItem(file, index);
        existing[key] = true;
        result.push(item);
        added.push(item);
    });
    return {items: result, added: added, ignored: ignored, rejected: rejected};
}
```

Every created item gets a monotonically increasing `local-N` ID and `status: 'queued'`; do not use a selection-wide version that invalidates callbacks from earlier selections.

- [ ] **Step 4: Implement a persistent two-worker scheduler**

The scheduler owns pending item IDs and active count. Before and after every awaited recognition, verify the item is still registered; removing an item marks it cancelled and revokes its object URL. `enqueue()` starts `pump()` immediately, and appended selections use the same scheduler instance.

```js
function createRecognitionQueue(worker, concurrency, onChange) {
    var pending = [], active = 0, records = {}, idleWaiters = [];

    function settleIdle() {
        if (active || pending.length) { return; }
        idleWaiters.splice(0).forEach(function (resolve) { resolve(); });
    }
    function pump() {
        while (active < concurrency && pending.length) {
            (function (id) {
                var item = records[id];
                if (!item || item.cancelled) { return; }
                active++;
                item.status = 'processing';
                onChange(item);
                Promise.resolve(worker(item)).then(function (result) {
                    if (records[id] === item && !item.cancelled) {
                        Object.assign(item, result || {}, {status: 'ready'});
                        onChange(item);
                    }
                }, function (error) {
                    if (records[id] === item && !item.cancelled) {
                        item.status = 'error';
                        item.error = error.message;
                        onChange(item);
                    }
                }).then(function () {
                    active--;
                    pump();
                    settleIdle();
                });
            }(pending.shift()));
        }
        settleIdle();
    }
    function enqueue(items) {
        items.forEach(function (item) { records[item.id] = item; pending.push(item.id); });
        pump();
    }
    function remove(id) {
        if (records[id]) { records[id].cancelled = true; delete records[id]; }
        pending = pending.filter(function (pendingId) { return pendingId !== id; });
        settleIdle();
    }
    function whenIdle() {
        if (!active && !pending.length) { return Promise.resolve(); }
        return new Promise(function (resolve) { idleWaiters.push(resolve); });
    }
    return {enqueue: enqueue, remove: remove, whenIdle: whenIdle};
}
```

Keep the existing `runPool()` and `analyzeFiles()` behavior for other pages. In `mountUpload()`, selection calls `appendFiles()`, renders ignored/rejected counts with `layer.msg`, resets the file input value so the same picker can fire again, then enqueues only `result.added`.

- [ ] **Step 5: Render every queue state and disable save while busy**

Update `uploadItemsHtml()` to map all fixed states to Chinese labels and state classes. Bind edits by `data-id` rather than array index so appended/removal operations cannot target the wrong card. Save is disabled when any item has `queued` or `processing`; error items remain manually editable and can become `ready` after valid edits.

- [ ] **Step 6: Run JS tests**

Run: `npm test`

Expected: all JS tests PASS, including the existing two-worker and editable-card tests.

- [ ] **Step 7: Commit the queue implementation**

```bash
git add public/js/qrcode-admin.js tests/js/qrcode-admin.test.js
git commit -m "feat: add bounded QR recognition queue"
```

### Task 4: Conflict Review UI and Batch Commit

**Files:**
- Modify: `public/js/qrcode-admin.js`
- Modify: `public/css/qrcode-admin.css`
- Modify: `tests/js/qrcode-admin.test.js`

**Interfaces:**
- Consumes: preview and commit endpoints from Task 2.
- Produces: `buildConflictDecisions(preview, mode, reviewChoice): Promise<Array<{client_id:string,action:string,target_id:?string}>>`.
- Produces: preview request `{type, items}` and commit request `{type, items, conflict_token, decisions}`.

- [ ] **Step 1: Write failing decision tests for every mode**

Build one preview fixture containing a database conflict at `20.00`, a batch group with three items at `10.00`, and one non-conflicting item. Assert:

```js
const preview = {
    has_conflicts: true,
    items: [
        {client_id: 'local-1', pay_url: 'wxp://1', price: '10.00', existing_id: null},
        {client_id: 'local-2', pay_url: 'wxp://2', price: '10.00', existing_id: null},
        {client_id: 'local-3', pay_url: 'wxp://3', price: '10.00', existing_id: null},
        {client_id: 'local-4', pay_url: 'wxp://4', price: '20.00', existing_id: '88'},
        {client_id: 'local-5', pay_url: 'wxp://5', price: '30.00', existing_id: null}
    ],
    batch_conflicts: [{price: '10.00', client_ids: ['local-1', 'local-2', 'local-3']}],
    database_conflicts: [{price: '20.00', existing_id: '88', client_ids: ['local-4']}]
};

test('replace all keeps the last selected batch item and replaces database rows', async () => {
    const decisions = await QrAdmin.buildConflictDecisions(preview, 'replace_all');
    assert.deepEqual(decisions.map((item) => [item.client_id, item.action, item.target_id]), [
        ['local-1', 'skip', null],
        ['local-2', 'skip', null],
        ['local-3', 'insert', null],
        ['local-4', 'replace', '88'],
        ['local-5', 'insert', null]
    ]);
});
```

Add `skip_all` expectations (first batch candidate wins, database-conflicting candidates skip) and `individual` expectations where an injected async `reviewChoice(current, candidate, conflict)` returns `replace` or `skip` in selection order.

- [ ] **Step 2: Run JS tests and verify failure**

Run: `npm test`

Expected: FAIL because conflict-decision planning does not exist.

- [ ] **Step 3: Implement deterministic client-side decisions**

Index preview items by normalized price and original position. Non-conflicting amounts become `insert`. For batch conflicts, initialize the first candidate as winner; later candidates either replace the winner (old winner becomes `skip`) or become `skip`. If the price has `existing_id`, the final winner action is `replace` targeting exactly that ID instead of `insert`.

Keep `buildConflictDecisions()` pure except for the injected `reviewChoice` callback. Reject unknown modes and malformed preview records instead of guessing.

- [ ] **Step 4: Replace individual POST saving with preview and commit**

Build payloads only from items whose URL and amount pass validation:

```js
var payloadItems = items.map(function (item) {
    return {client_id: item.id, pay_url: item.url.trim(), price: item.amount.trim()};
});
return request({
    url: 'index.php/api/qrcode/batch/preview',
    method: 'POST',
    data: {type: type, items: payloadItems}
});
```

If `has_conflicts` is false, generate all-insert decisions and commit immediately. Otherwise show one Layui dialog with three explicit commands: `全部替换`, `逐个确认`, `全部放弃冲突项`. Individual review displays payment type, amount, current winner filename/URL, candidate filename/URL, and asks `替换` or `放弃` for each later candidate in selection order.

- [ ] **Step 5: Handle commit results, stale state, and failures**

On success, use per-item results to set `saved`/`skipped`, revoke saved object URLs, remove only saved and skipped cards from the active queue, preserve failed cards with returned messages, and show all four totals.

Enhance `request()` so rejected application responses retain structured fields instead of reducing them to a message:

```js
function responseError(response, fallback) {
    var error = new Error((response && response.msg) || fallback);
    error.code = response && Number(response.code);
    error.data = response && response.data;
    return error;
}
```

Use this helper for non-200 JSON in both the Ajax success callback and `xhr.responseJSON` error callback. Add a Node test for `responseError({code: 409, msg: 'changed', data: {preview: {}}})` so the stale preview remains accessible.

On application response code `409`, keep every item, use `error.data.preview`, and restart the conflict review. On preview/commit network error, keep the complete queue and all edits. Disable selection removal and save only as needed to prevent a second simultaneous commit; recognition of newly appended files can continue, but the commit snapshot remains keyed by stable client IDs.

- [ ] **Step 6: Add compact responsive styles**

Add state modifiers such as:

```css
.qr-status--queued { background: #eef1f4; color: #4f5b66; }
.qr-status--processing { background: #e8f3ff; color: #1769aa; }
.qr-status--ready, .qr-status--saved { background: #e8f6ed; color: #237a45; }
.qr-status--error { background: #fff0f0; color: #b42318; }
.qr-status--skipped { background: #f6f1e8; color: #795b24; }
.qr-conflict-summary { max-height: min(55vh, 420px); overflow: auto; }
```

Keep cards at the management-list dimensions, prevent nested cards, and ensure toolbar/buttons wrap at 760px and 420px widths without text overlap.

- [ ] **Step 7: Run JS and PHP tests**

Run: `npm test`

Expected: all JS tests PASS.

Run: `php tests/run.php`

Expected: all PHP tests PASS.

- [ ] **Step 8: Commit conflict-aware saving**

```bash
git add public/js/qrcode-admin.js public/css/qrcode-admin.css tests/js/qrcode-admin.test.js
git commit -m "feat: add QR batch conflict review"
```

### Task 5: Management Sorting and Per-Type Persistence

**Files:**
- Modify: `public/js/qrcode-list.js`
- Modify: `public/css/qrcode-admin.css`
- Modify: `tests/js/qrcode-list.test.js`

**Interfaces:**
- Consumes: list API `sort` token support from Task 2.
- Produces: `sortStorageKey(type): string`.
- Produces: `normalizeSort(value): string` with the same six tokens as PHP.
- Produces: `listUrl(endpoint, state): string` containing `page`, `limit`, and `sort`.

- [ ] **Step 1: Write failing sort helper tests**

Add:

```js
test('builds whitelisted server-side sort requests and separate storage keys', () => {
    assert.equal(QrList.sortStorageKey(1), 'vmqfox:qrcode-sort:wechat');
    assert.equal(QrList.sortStorageKey(2), 'vmqfox:qrcode-sort:alipay');
    assert.equal(QrList.normalizeSort('amount_desc'), 'amount_desc');
    assert.equal(QrList.normalizeSort('id desc; drop table'), 'newest');
    assert.equal(
        QrList.listUrl('index.php/api/qrcode/wechat', {page: 2, limit: 24, sort: 'amount_asc'}),
        'index.php/api/qrcode/wechat?page=2&limit=24&sort=amount_asc'
    );
});
```

- [ ] **Step 2: Run JS tests and verify failure**

Run: `npm test`

Expected: FAIL because sort helpers do not exist.

- [ ] **Step 3: Implement whitelist helpers and safe storage access**

Define the same token list as PHP. Wrap localStorage reads/writes in `try/catch` so private browsing/storage denial falls back to `newest` without breaking the list. Export the pure helpers for Node tests.

- [ ] **Step 4: Add the management sort selector**

Add a labeled select next to page size with exact values:

```html
<label class="qr-page-sort">排序
  <select data-role="sort">
    <option value="newest">最新优先</option>
    <option value="oldest">最早优先</option>
    <option value="amount_asc">金额从低到高</option>
    <option value="amount_desc">金额从高到低</option>
    <option value="enabled_first">启用优先</option>
    <option value="disabled_first">禁用优先</option>
  </select>
</label>
```

Initialize `state.sort` from `sortStorageKey(type)`. On change, normalize and persist the selected token, set `state.page = 1`, and call `load()`. Use `listUrl(endpoint, state)` so the API, not the browser, sorts the current page.

- [ ] **Step 5: Style the sort control with existing toolbar inputs**

Include `.qr-page-sort` and `.qr-page-sort select` in the existing page-size rules; set a bounded `min-width` and allow toolbar wrapping so long Chinese labels do not overlap refresh/dependency buttons.

- [ ] **Step 6: Run JS and PHP tests**

Run: `npm test`

Expected: all JS tests PASS.

Run: `php tests/run.php`

Expected: all PHP tests PASS.

- [ ] **Step 7: Commit management sorting**

```bash
git add public/js/qrcode-list.js public/css/qrcode-admin.css tests/js/qrcode-list.test.js
git commit -m "feat: add server-side QR list sorting"
```

### Task 6: Browser Smoke Coverage, Documentation, Version, and Release Readiness

**Files:**
- Modify: `tests/browser/qr-upload-smoke.html`
- Modify: `tests/browser/qrcode-delete-smoke.html`
- Modify: `public/aaa.html`
- Modify: `.gitattributes`
- Modify: `README.md`
- Modify: `ver`

**Interfaces:**
- Verifies: both payment types use the same upload behavior while keeping independent duplicate scope and sort state.
- Produces: cache-busted browser assets and current public documentation.

- [ ] **Step 1: Extend the browser smoke harness**

Make the upload smoke page expose deterministic fake preview/commit responses and render a 20-card queue. Its assertions must check: a second selection appends, file 21 is rejected, cards remain editable, conflict actions can be selected, and the final request is one batch commit rather than many single-add requests.

Update `tests/browser/qrcode-delete-smoke.html` to assert the requested URL contains `sort=amount_desc` after changing the selector and that page resets to `1`.

- [ ] **Step 2: Run automated suites before browser inspection**

Run: `php composer.phar install --no-interaction --prefer-dist`

Run: `php tests/run.php`

Expected: all PHP tests PASS.

Run: `npm test`

Expected: all JS tests PASS.

Run: `python -m unittest discover -s tests/python -p "test_*.py"`

Expected: all Python QR decoder tests PASS or dependency-specific skips already defined by the suite.

- [ ] **Step 3: Perform desktop and mobile browser verification**

Serve `public/` using the project's normal local PHP entry point, then verify at approximately 1440x900 and 390x844:

```text
WeChat: select 3 files -> append 2 -> edit amount -> save -> exercise each conflict mode
Alipay: repeat with a different stored sort and confirm it does not alter WeChat sort
Management: choose every sort -> page next/back -> refresh -> confirm stable persisted order
Failure: force preview/commit 500 -> confirm every queue item and edit remains
Stale: return 409 with refreshed preview -> confirm conflict review reopens with no partial removal
```

Capture console output and confirm there are no uncaught exceptions, clipped controls, overlapping text, or blank QR grids.

- [ ] **Step 4: Bump browser cache keys and release version**

Change both QR script query strings in `public/aaa.html` from `v=20260817-6` to `v=20260817-7`. Change the first README version statement to `2.3.4` and change `ver` to `2.3.4|2026-08-17`; do not change the ThinkPHP framework version in `config/app.php`.

Add this release-only exclusion to `.gitattributes` so the deployment archive does not contain internal planning files:

```gitattributes
docs/superpowers export-ignore
```

- [ ] **Step 5: Update user documentation**

Document these exact operational facts in the existing Chinese README/release notes:

```text
- 微信和支付宝每次队列最多 20 张图片，识别并发数为 2。
- 新选择会追加，完全相同的文件会自动忽略，识别后金额和二维码内容仍可人工修改。
- 同支付类型、同金额会在保存前要求全部替换、逐个确认或全部放弃冲突项。
- 替换仅更新原记录的二维码内容，不改变 ID、金额和启用状态。
- 管理页排序由 API 在数据库分页前执行，微信和支付宝分别记忆排序方式。
```

- [ ] **Step 6: Run final verification and inspect the diff**

Run: `php tests/run.php`

Run: `npm test`

Run: `python -m unittest discover -s tests/python -p "test_*.py"`

Run: `git diff --check`

Run: `git status --short`

Expected: every test suite PASS, `git diff --check` prints nothing, and status lists only intended feature/documentation files.

- [ ] **Step 7: Commit release-ready changes**

```bash
git add tests/browser/qr-upload-smoke.html tests/browser/qrcode-delete-smoke.html public/aaa.html .gitattributes README.md ver
git commit -m "docs: release QR batch management update"
```

- [ ] **Step 8: Push and publish only after local verification**

Check `git status --short`, `git log --oneline -8`, and `git remote -v`. Push the verified commits to `origin/main`, then build and publish the patch release:

```bash
git push origin main
git archive --format=zip --output=vmqfox-backend-v2.3.4.zip HEAD
gh release create v2.3.4 vmqfox-backend-v2.3.4.zip --repo LeoChen-CoreMind/vmqfox-backend --target main --title "VMQFox Backend v2.3.4" --generate-notes
```

Inspect the archive with `tar -tf vmqfox-backend-v2.3.4.zip` and confirm it contains tracked application/install files but not `.env`, `.env.docker`, `vendor/`, `runtime/`, `node_modules/`, or the ignored plan/spec directories.
