const test = require('node:test');
const assert = require('node:assert/strict');
const QrAdmin = require('../../public/js/qrcode-admin.js');

const conflictPreview = {
    has_conflicts: true,
    conflict_token: 'a'.repeat(64),
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

test('exports the upload mount function under both supported names', () => {
    assert.equal(QrAdmin.mount, QrAdmin.mountUpload);
});

test('accepts positive money with up to two decimals', () => {
    assert.equal(QrAdmin.isValidAmount('1.00'), true);
    assert.equal(QrAdmin.isValidAmount('1.2'), true);
    assert.equal(QrAdmin.isValidAmount('0'), false);
    assert.equal(QrAdmin.isValidAmount('1.001'), false);
});

test('never runs more than two analyses', async () => {
    let active = 0;
    let max = 0;
    await QrAdmin.runPool([1, 2, 3, 4, 5], 2, async () => {
        active++;
        max = Math.max(max, active);
        await new Promise((resolve) => setTimeout(resolve, 10));
        active--;
    });
    assert.equal(max, 2);
});

test('replace all keeps the last selected batch item and replaces database rows', async () => {
    const decisions = await QrAdmin.buildConflictDecisions(conflictPreview, 'replace_all');

    assert.deepEqual(decisions.map((item) => [item.client_id, item.action, item.target_id ?? null]), [
        ['local-1', 'skip', null],
        ['local-2', 'skip', null],
        ['local-3', 'insert', null],
        ['local-4', 'replace', '88'],
        ['local-5', 'insert', null]
    ]);
});

test('skip all keeps the first upload-only candidate and skips database conflicts', async () => {
    const decisions = await QrAdmin.buildConflictDecisions(conflictPreview, 'skip_all');

    assert.deepEqual(decisions.map((item) => [item.client_id, item.action, item.target_id ?? null]), [
        ['local-1', 'insert', null],
        ['local-2', 'skip', null],
        ['local-3', 'skip', null],
        ['local-4', 'skip', null],
        ['local-5', 'insert', null]
    ]);
});

test('individual review resolves candidates in selection order and confirms database replacement', async () => {
    const calls = [];
    const choices = ['replace', 'skip', 'replace'];
    const decisions = await QrAdmin.buildConflictDecisions(conflictPreview, 'individual', async (current, candidate, conflict) => {
        calls.push([current && current.client_id, candidate.client_id, conflict.kind]);
        return choices.shift();
    });

    assert.deepEqual(calls, [
        ['local-1', 'local-2', 'batch'],
        ['local-2', 'local-3', 'batch'],
        [null, 'local-4', 'database']
    ]);
    assert.deepEqual(decisions.map((item) => [item.client_id, item.action, item.target_id ?? null]), [
        ['local-1', 'skip', null],
        ['local-2', 'insert', null],
        ['local-3', 'skip', null],
        ['local-4', 'replace', '88'],
        ['local-5', 'insert', null]
    ]);
    assert.equal(Object.prototype.hasOwnProperty.call(decisions[0], 'target_id'), false);
    assert.equal(Object.prototype.hasOwnProperty.call(decisions[3], 'target_id'), true);
});

test('structured response errors retain stale preview data', () => {
    const error = QrAdmin.responseError({code: 409, msg: 'changed', data: {preview: conflictPreview}}, 'fallback');

    assert.equal(error.message, 'changed');
    assert.equal(error.code, 409);
    assert.equal(error.data.preview, conflictPreview);
});

test('appends selections, ignores exact files, and enforces the combined cap', () => {
    const make = (name, size, lastModified) => ({name, size, lastModified, type: 'image/jpeg'});
    const initial = [{id: 'local-1', file: make('a.jpg', 10, 1)}];
    const files = [make('a.jpg', 10, 1)].concat(
        Array.from({length: 21}, (_, index) => make('n' + index + '.jpg', index + 1, index + 2))
    );
    const result = QrAdmin.appendFiles(initial, files, (file, index) => ({id: 'new-' + index, file}), 20);

    assert.equal(result.items.length, 20);
    assert.equal(result.ignored, 1);
    assert.equal(result.rejected, 2);
});

test('accepts multiple dropped images and rejects non-images before queue capacity', () => {
    const result = QrAdmin.partitionImageFiles([
        {name: 'one.jpg', type: 'image/jpeg'},
        {name: 'notes.txt', type: 'text/plain'},
        {name: 'two.png', type: 'image/png'}
    ]);

    assert.deepEqual(result.accepted.map((file) => file.name), ['one.jpg', 'two.png']);
    assert.deepEqual(result.rejected.map((file) => file.name), ['notes.txt']);
});

test('persistent recognition queue accepts appended work and ignores removed callbacks', async () => {
    let active = 0;
    let max = 0;
    const resolvers = [];
    const changes = [];
    const queue = QrAdmin.createRecognitionQueue((item) => new Promise((resolve) => {
        active++;
        max = Math.max(max, active);
        resolvers.push(() => { active--; resolve({url: 'done:' + item.id}); });
    }), 2, (item) => changes.push(item.id + ':' + item.status));
    const items = [1, 2, 3, 4].map((id) => ({id: 'local-' + id, status: 'queued'}));

    queue.enqueue(items.slice(0, 2));
    queue.enqueue(items.slice(2));
    queue.remove('local-4');
    await new Promise((resolve) => setImmediate(resolve));
    resolvers.splice(0, 2).forEach((resolve) => resolve());
    await new Promise((resolve) => setImmediate(resolve));
    resolvers.splice(0).forEach((resolve) => resolve());
    await queue.whenIdle();

    assert.equal(max, 2);
    assert.equal(changes.some((entry) => entry.startsWith('local-4:')), false);
    assert.equal(items[2].url, 'done:local-3');
});

test('recognition queue never exceeds the fixed two-worker limit', async () => {
    let active = 0;
    let max = 0;
    const queue = QrAdmin.createRecognitionQueue(async () => {
        active++;
        max = Math.max(max, active);
        await new Promise((resolve) => setTimeout(resolve, 5));
        active--;
    }, 9, () => {});

    queue.enqueue([1, 2, 3, 4].map((id) => ({id: 'local-cap-' + id, status: 'queued'})));
    await queue.whenIdle();

    assert.equal(max, 2);
});

function createFakeEventTarget() {
    const listeners = {};
    return {
        addEventListener(type, listener) { (listeners[type] ||= []).push(listener); },
        removeEventListener(type, listener) {
            listeners[type] = (listeners[type] || []).filter((entry) => entry !== listener);
        },
        dispatch(type, event) { (listeners[type] || []).slice().forEach((listener) => listener(event)); }
    };
}

function dragEvent(files) {
    return {
        dataTransfer: {files, types: ['Files']},
        preventDefault() {}
    };
}

test('whole-page drop binding reports drag state, forwards files, and cleans up', () => {
    const root = createFakeEventTarget();
    const states = [];
    const drops = [];
    const destroy = QrAdmin.bindDropTarget(root, (files) => drops.push(files), (active) => states.push(active));

    root.dispatch('dragenter', dragEvent([]));
    root.dispatch('drop', dragEvent([{name: 'one.jpg', type: 'image/jpeg'}]));
    destroy();
    root.dispatch('dragenter', dragEvent([]));

    assert.deepEqual(states, [true, false, false]);
    assert.equal(drops.length, 1);
    assert.equal(drops[0][0].name, 'one.jpg');
});

test('dependency help includes common install commands and no gd requirement', () => {
    const html = QrAdmin.dependencyHelpHtml({proc_open: true, temp_dir_writable: true});
    assert.match(html, /ZXing-C\+\+/);
    assert.match(html, /apt install -y zbar-tools python3-opencv python3-zxing-cpp tesseract-ocr.*php-xml/);
    assert.match(html, /python3 -m pip install opencv-python-headless zxing-cpp/);
    assert.match(html, /不需要安装 PHP GD/);
});

test('dependency help explains that proc_open must be enabled before tool detection', () => {
    const html = QrAdmin.dependencyHelpHtml({proc_open: false, temp_dir_writable: true});

    assert.match(html, /必须先解除 PHP 限制/);
    assert.match(html, /无法检测（先移除 proc_open 禁用）/);
    assert.match(html, /未移除前，系统无法检测或运行 zbarimg、ZXing-C\+\+、OpenCV 和 Tesseract/);
});

test('upload items use management-style cards with editable URL and amount', () => {
    const html = QrAdmin.uploadItemsHtml([{
        file: {name: 'wechat.jpg'},
        id: 'local-7',
        preview: 'blob:preview',
        url: 'wxp://example',
        amount: '1.00',
        amountStatus: 'detected',
        status: 'ready',
        decoder: 'opencv'
    }]);

    assert.match(html, /class="qr-card qr-upload-card"/);
    assert.match(html, /data-id="local-7"/);
    assert.match(html, /data-field="url"/);
    assert.match(html, /value="wxp:\/\/example"/);
    assert.match(html, /data-field="amount"/);
});

test('renders saved and skipped upload queue states distinctly', () => {
    const base = {
        file: {name: 'wechat.jpg'},
        preview: 'blob:preview',
        url: 'wxp://example',
        amount: '1.00',
        amountStatus: 'manual'
    };
    const html = QrAdmin.uploadItemsHtml([
        {...base, id: 'local-1', status: 'saved'},
        {...base, id: 'local-2', status: 'skipped'}
    ]);

    assert.match(html, /qr-status is-saved/);
    assert.match(html, /qr-status is-skipped/);
    assert.match(html, />已保存<\/span>/);
    assert.match(html, />已放弃<\/span>/);
});
