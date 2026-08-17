# QR Code Batch Upload and Sorting Design

## Goal

Extend the WeChat and Alipay QR administration pages with additive multi-image selection, a bounded recognition queue, conflict-aware batch persistence, and database-backed list sorting. Duplicate amounts are scoped to the same payment type.

## Upload Queue

- The file picker remains multi-select.
- Every new selection appends to the current queue instead of replacing it.
- The queue holds at most 20 images. Files beyond the remaining capacity are rejected with a visible message.
- An exact repeated file is ignored when its name, size, and last-modified timestamp match an item already in the queue.
- At most two image analyses run concurrently. Remaining cards stay in the queued state until a worker is available.
- Each card exposes its preview, filename, QR content, amount, recognition detail, status, and remove action.
- QR content and amount remain editable after automatic recognition.
- Queue states are: queued, processing, ready, error, skipped, and saved.

## Duplicate Scope

- WeChat uploads compare only with existing WeChat records.
- Alipay uploads compare only with existing Alipay records.
- Duplicate amounts inside the current upload batch are also conflicts, even when the database does not yet contain that amount.
- Prices are normalized to two decimal places before comparison.

## Conflict Flow

Saving is a two-stage operation:

1. The frontend submits the valid queue items to a batch preview endpoint.
2. The server returns database conflicts and within-batch conflict groups without writing data.
3. When conflicts exist, the frontend offers:
   - Replace all
   - Review individually
   - Skip all conflicts
4. Replace all uses the last selected image as the winner for each duplicated amount.
5. Review individually processes later images in selection order. Each prompt asks whether the candidate should replace the current winner or be skipped.
6. Skip all conflicts excludes only conflicting candidates. Non-conflicting items continue to save.
7. The frontend submits the resulting insert, replace, and skip decisions to the batch commit endpoint.

For a database conflict, replace updates only `pay_url` on the existing row. It preserves the existing row ID, amount, and enabled/disabled state. For an upload-only conflict, exactly one image for that amount is inserted.

After commit, the page reports inserted, replaced, skipped, and failed counts. Saved items leave the queue; failed items remain editable with their error messages.

## Backend Consistency

- The preview endpoint validates type, QR content, normalized amount, queue size, and client item identifiers.
- The commit endpoint accepts explicit decisions generated from the preview response.
- Commit runs in a database transaction.
- QR writes acquire a row lock on a stable `setting` record before checking amounts and changing `pay_qrcode`.
- Existing single-item create and amount-update endpoints acquire the same lock and reject same-type duplicate amounts.
- Commit rechecks all conflicts while holding the lock. If state changed after preview, it returns refreshed conflicts without applying a partial batch.
- A failed commit rolls back every insert and replacement in that commit.
- Request-provided sort fields, SQL fragments, record IDs for replacement, and payment types are never trusted without server validation.

## Batch API

### Preview

`POST /api/qrcode/batch/preview`

Input:

```json
{
  "type": 1,
  "items": [
    {"client_id": "local-1", "pay_url": "wxp://...", "price": "10.00"}
  ]
}
```

Output contains normalized items, same-type database matches, within-batch groups, and stable existing record IDs for display. No records are changed.

### Commit

`POST /api/qrcode/batch/commit`

Input contains the same normalized items plus explicit per-item actions: `insert`, `replace`, or `skip`. A replace action may target only the existing row returned by preview for the same type and amount.

Output contains per-item results and inserted, replaced, skipped, and failed totals.

## Management Sorting

Both management pages add a sorting menu with:

- Newest first (default)
- Oldest first
- Amount low to high
- Amount high to low
- Enabled first
- Disabled first

The selected mode is sent to the list API and applied in SQL before pagination. Every sort includes an ID tie-breaker so page order remains stable. The server maps fixed sort tokens to known columns and directions; arbitrary SQL is rejected or falls back to the default.

WeChat and Alipay pages store their last selected sort independently in browser local storage. Changing sort resets the current page to 1 and requests only that page from the API.

## Error Handling

- Saving is disabled while any item is queued or processing.
- Invalid or missing QR content and amounts are reported on the relevant card.
- Preview or commit network failures preserve the complete queue.
- A stale conflict response triggers a fresh conflict review instead of silently overwriting data.
- Removing an item revokes its object URL and excludes it from all later recognition callbacks.
- Selecting files while recognition is active appends work without invalidating the existing queue.

## Testing

Frontend tests cover additive selections, exact-file deduplication, the 20-item cap, two-worker recognition, every conflict decision mode, queue retention on failure, and sort persistence.

Backend tests cover normalized duplicate detection, payment-type isolation, upload-only duplicate groups, replace-in-place behavior, transaction rollback, stale decisions, shared write locking, sort-token whitelisting, and stable paginated order.

Existing single-upload, manual-edit, deletion, enable/disable, and API pagination behavior must remain compatible.
