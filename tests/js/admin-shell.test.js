const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const publicRoot = path.resolve(__dirname, '../../public');

test('admin shell initializes QR fragments after jQuery load completes', () => {
    const shell = fs.readFileSync(path.join(publicRoot, 'aaa.html'), 'utf8');

    assert.match(shell, /function loadAdminPage\(url\)/);
    assert.match(shell, /adminPageRequest\.abort\(\)/);
    assert.match(shell, /version !== adminPageVersion/);
    assert.match(shell, /QrAdmin\.mountUpload\(\{root: "#qr-upload-root", type: 1\}\)/);
    assert.match(shell, /QrAdmin\.mountUpload\(\{root: "#qr-upload-root", type: 2\}\)/);
    assert.match(shell, /QrList\.mount\(\{root: "#qr-list-root", type: 1\}\)/);
    assert.match(shell, /QrList\.mount\(\{root: "#qr-list-root", type: 2\}\)/);
});

test('admin shell inline scripts are valid JavaScript', () => {
    const shell = fs.readFileSync(path.join(publicRoot, 'aaa.html'), 'utf8');
    const inlineScripts = [...shell.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)];

    inlineScripts.forEach((match) => {
        assert.doesNotThrow(() => new Function(match[1]));
    });
});

test('admin shell logs out through the API without automatic update prompts', () => {
    const shell = fs.readFileSync(path.join(publicRoot, 'aaa.html'), 'utf8');

    assert.match(shell, /index\.php\/api\/auth\/logout/);
    assert.doesNotMatch(shell, /admin\/index\/checkUpdate/);
});

test('QR fragments contain only their mount roots', () => {
    const fragments = [
        'admin/addwxqrcode.html',
        'admin/addzfbqrcode.html',
        'admin/wxqrcodelist.html',
        'admin/zfbqrcodelist.html'
    ];

    fragments.forEach((fragment) => {
        const html = fs.readFileSync(path.join(publicRoot, fragment), 'utf8');
        assert.doesNotMatch(html, /<script\b/i, fragment);
    });
});
