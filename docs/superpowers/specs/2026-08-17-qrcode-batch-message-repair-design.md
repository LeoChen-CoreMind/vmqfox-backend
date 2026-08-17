# QR Code Batch Message Repair Design

## Goal

Repair every user-facing validation message emitted by `QrcodeBatch` so the WeChat and Alipay batch upload pages always display readable UTF-8 Chinese. The repair must also verify the preview and commit paths behind the reported save failure instead of merely changing the visible text.

## Scope

- Replace all corrupted Chinese and English `InvalidArgumentException` messages in `app/service/QrcodeBatch.php` with concise Chinese messages.
- Cover item normalization, payment type validation, conflict-decision normalization, preview integrity checks, and commit-plan validation.
- Keep method signatures, accepted request fields, action names, conflict behavior, response shapes, and HTTP status handling unchanged.
- Do not introduce an error-code or internationalization layer in this patch.

## Message Rules

- Each message describes the rejected input or decision and, where useful, the valid requirement.
- Repeated invalid-decision branches use one consistent message.
- Messages must be valid UTF-8 and must not contain known mojibake fragments such as `娴`, `浼`, `閸`, or the replacement character `�`.
- Internal runtime failures outside `QrcodeBatch` are not changed unless reproduction proves they are part of the same user-visible failure.

## Data Flow

The frontend continues to submit batch items to the preview endpoint and explicit decisions to the commit endpoint. Controllers continue catching `InvalidArgumentException` and returning the exception message through the existing API response. Only the message content changes; successful recognition, conflict grouping, replacement, skipping, and insertion remain behaviorally identical.

## Failure Reproduction

Tests will exercise invalid inputs across all public `QrcodeBatch` methods. In particular, they will cover incomplete or malformed decisions and invalid preview/commit data, because these branches execute after recognition and can produce the toast shown in the report. A valid preview-and-commit fixture will remain as the success baseline so message repair cannot mask a logic regression.

## Testing

- Assert representative validation branches return their exact intended Chinese message.
- Collect every user-facing exception literal in `QrcodeBatch.php` and fail if it contains known mojibake markers or unintended English validation text.
- Run the existing PHP test suite to confirm batch conflict and persistence planning behavior remains compatible.
- Run syntax and diff checks before publishing.

## Acceptance Criteria

- No corrupted or English user-facing validation message remains in `QrcodeBatch.php`.
- Batch preview and commit errors display readable Chinese through the unchanged API contract.
- The valid batch recognition and save plan still passes existing tests.
- The reported post-recognition failure path either succeeds for valid input or returns a precise readable reason for invalid input.
