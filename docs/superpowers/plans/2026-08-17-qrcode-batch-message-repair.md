# QR Code Batch Message Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every QR batch validation error display readable Chinese while preserving the existing preview, conflict, and commit behavior.

**Architecture:** Keep validation in `QrcodeBatch` and keep controllers and API response shapes unchanged. Add focused unit assertions for the decision path seen after recognition plus a source-level guard that rejects mojibake or remaining English-only exception literals.

**Tech Stack:** PHP 8.2, existing custom PHP test runner, ThinkPHP application services

## Global Constraints

- Keep all `QrcodeBatch` public method signatures and return shapes unchanged.
- Keep `insert`, `replace`, and `skip` action semantics unchanged.
- Do not add an internationalization or error-code layer.
- All new user-facing validation messages must be valid UTF-8 Chinese.

---

### Task 1: Lock Down Readable Validation Messages

**Files:**
- Modify: `tests/QrcodeBatchTest.php`
- Test: `tests/QrcodeBatchTest.php`

**Interfaces:**
- Consumes: `QrcodeBatch::normalizeItems()`, `QrcodeBatch::preview()`, `QrcodeBatch::normalizeDecisions()`, and `QrcodeBatch::commitPlan()`
- Produces: regression coverage for exact Chinese messages and a scan of every `InvalidArgumentException` literal in `QrcodeBatch.php`

- [ ] **Step 1: Add a reusable exception-message assertion**

```php
function assertQrcodeBatchMessage(string $expected, callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assertSameValue($expected, $exception->getMessage());
        return;
    }

    throw new RuntimeException('expected InvalidArgumentException');
}
```

- [ ] **Step 2: Add exact assertions for input, type, decision, preview, and commit errors**

```php
test('returns readable Chinese messages for batch validation failures', function (): void {
    assertQrcodeBatchMessage('每批必须包含 1 到 20 个二维码', fn () => QrcodeBatch::normalizeItems([]));
    assertQrcodeBatchMessage('支付类型错误', fn () => QrcodeBatch::preview(3, [
        ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
    ], []));

    $items = QrcodeBatch::normalizeItems([
        ['client_id' => 'local-1', 'pay_url' => 'wxp://one', 'price' => '10'],
    ]);
    assertQrcodeBatchMessage('二维码处理决定不完整', fn () => QrcodeBatch::normalizeDecisions([], $items));
    assertQrcodeBatchMessage('二维码预览数据无效', fn () => QrcodeBatch::commitPlan([], []));
});
```

- [ ] **Step 3: Add a complete literal guard for future regressions**

```php
test('contains no mojibake or English-only batch exception messages', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/service/QrcodeBatch.php');
    if ($source === false) {
        throw new RuntimeException('unable to read QrcodeBatch source');
    }

    preg_match_all("/throw new InvalidArgumentException\\('([^']*)'\\);/u", $source, $matches);
    if ($matches[1] === []) {
        throw new RuntimeException('expected QrcodeBatch exception messages');
    }

    foreach ($matches[1] as $message) {
        if (preg_match('/[娴浼閸濞闁锟�]/u', $message) === 1) {
            throw new RuntimeException('mojibake remains: ' . $message);
        }
        if (preg_match('/^[\\x00-\\x7F]+$/', $message) === 1) {
            throw new RuntimeException('English-only message remains: ' . $message);
        }
    }
});
```

- [ ] **Step 4: Run the PHP test suite and confirm the new assertions fail for current corrupted text**

Run: `php tests/run.php`

Expected: existing functional tests pass, while the new exact-message or mojibake guard tests fail against the current `QrcodeBatch.php` strings.

- [ ] **Step 5: Commit the failing regression tests**

```bash
git add tests/QrcodeBatchTest.php
git commit -m "test: expose QR batch message corruption"
```

---

### Task 2: Replace Corrupted Service Messages

**Files:**
- Modify: `app/service/QrcodeBatch.php`
- Test: `tests/QrcodeBatchTest.php`

**Interfaces:**
- Consumes: the unchanged validation branches and exception propagation already used by `app/controller/api/Qrcode.php`
- Produces: readable Chinese `InvalidArgumentException::getMessage()` values without changing API data structures

- [ ] **Step 1: Replace every item and type validation literal**

Use these exact mappings:

```text
invalid batch size                 -> 每批必须包含 1 到 20 个二维码
invalid item payload               -> 二维码内容或金额无效
invalid or duplicate client_id     -> 二维码客户端编号无效或重复
invalid payment type               -> 支付类型错误
```

- [ ] **Step 2: Replace every decision normalization literal**

Use these exact mappings:

```text
decisions is not an array          -> 每个二维码都必须提交处理决定
invalid decision record/action     -> 二维码处理决定无效
invalid/duplicate decision ID      -> 处理决定的客户端编号无效或重复
decision count mismatch            -> 二维码处理决定不完整
missing decision for an item       -> 二维码处理决定不完整
invalid replacement target         -> 替换操作必须指定有效的二维码编号
target on a non-replace action      -> 非替换操作不能指定目标二维码
unknown decision client ID         -> 二维码处理决定无效
```

- [ ] **Step 3: Replace every commit-plan validation literal**

Use these exact mappings:

```text
invalid preview structure          -> 二维码预览数据无效
missing or invalid decision        -> 二维码处理决定无效
insert conflicts with existing row -> 该金额已存在二维码，不能新增
multiple inserts for one amount    -> 同一金额只能新增一个二维码
replacement target mismatch        -> 替换目标与预览冲突不匹配
skip action with target ID          -> 跳过操作不能指定目标二维码
decision/plan count mismatch       -> 二维码处理决定不完整
```

- [ ] **Step 4: Run syntax and focused behavior checks**

Run: `php -l app/service/QrcodeBatch.php`

Expected: `No syntax errors detected in app/service/QrcodeBatch.php`

Run: `php tests/run.php`

Expected: all tests pass, including the exact Chinese assertions and literal guard.

- [ ] **Step 5: Commit the service repair**

```bash
git add app/service/QrcodeBatch.php
git commit -m "fix: repair QR batch validation messages"
```

---

### Task 3: Verify the End-to-End Contract

**Files:**
- Verify: `app/controller/api/Qrcode.php`
- Verify: `public/js/qrcode-admin.js`
- Verify: `app/service/QrcodeBatch.php`
- Verify: `tests/QrcodeBatchTest.php`

**Interfaces:**
- Consumes: existing `/api/qrcode/batch/preview` and `/api/qrcode/batch/commit` controller contracts
- Produces: release evidence that valid batches still save and invalid batches return readable messages through the existing frontend toast path

- [ ] **Step 1: Confirm controllers still return service validation messages unchanged**

Run: `rg -n "QrcodeBatch::|InvalidArgumentException|failure\(" app/controller/api/Qrcode.php`

Expected: preview and commit call `QrcodeBatch`, catch `InvalidArgumentException`, and return the exception message without response-shape changes.

- [ ] **Step 2: Run all repository tests used by the QR administration feature**

Run: `php tests/run.php`

Expected: exit code `0` and no `FAIL` lines.

Run: `node --test tests/js/*.test.js`

Expected: exit code `0` and no failed tests.

- [ ] **Step 3: Check the final diff for encoding artifacts and whitespace errors**

Run: `rg -n "Invalid payment type|Invalid QR|QR batch|[娴浼閸濞闁锟�]" app/service/QrcodeBatch.php`

Expected: no matches.

Run: `git diff --check HEAD~2..HEAD`

Expected: no output.

- [ ] **Step 4: Record the verified result in the final implementation commit if any test-only adjustment was needed**

```bash
git add app/service/QrcodeBatch.php tests/QrcodeBatchTest.php
git commit -m "test: verify readable QR batch errors"
```

Skip this commit when verification requires no file changes.
