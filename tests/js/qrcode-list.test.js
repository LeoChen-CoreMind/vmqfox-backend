const test = require('node:test');
const assert = require('node:assert/strict');
const QrList = require('../../public/js/qrcode-list.js');

test('builds a bounded page window', () => {
    assert.deepEqual(QrList.pageWindow(1, 10), [1, 2, 3, 4, 5]);
    assert.deepEqual(QrList.pageWindow(8, 10), [6, 7, 8, 9, 10]);
});

test('moves back after deleting the last item on a page', () => {
    assert.equal(QrList.afterDeletePage(3, 1), 2);
    assert.equal(QrList.afterDeletePage(3, 2), 3);
    assert.equal(QrList.afterDeletePage(1, 1), 1);
});

test('preserves database IDs without JavaScript number conversion', () => {
    assert.equal(QrList.normalizeId('9007199254740993'), '9007199254740993');
    assert.equal(QrList.normalizeId('17'), '17');
    assert.equal(QrList.normalizeId('invalid'), '');
});

test('sends deletion IDs in a POST body', () => {
    assert.deepEqual(QrList.deleteRequest('17'), {
        url: 'index.php/api/qrcode/delete',
        method: 'POST',
        data: {id: '17'}
    });
});

test('sends the requested enabled state to the bind endpoint', () => {
    assert.deepEqual(QrList.toggleRequest('17', false), {
        url: 'index.php/api/qrcode/bind/17',
        method: 'POST',
        data: {state: 1}
    });
    assert.deepEqual(QrList.toggleRequest('17', true), {
        url: 'index.php/api/qrcode/bind/17',
        method: 'POST',
        data: {state: 0}
    });
});
