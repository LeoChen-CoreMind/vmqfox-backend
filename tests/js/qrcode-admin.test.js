const test = require('node:test');
const assert = require('node:assert/strict');
const QrAdmin = require('../../public/js/qrcode-admin.js');

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
        preview: 'blob:preview',
        url: 'wxp://example',
        amount: '1.00',
        amountStatus: 'detected',
        status: 'ready',
        decoder: 'opencv'
    }]);

    assert.match(html, /class="qr-card qr-upload-card"/);
    assert.match(html, /data-field="url"/);
    assert.match(html, /value="wxp:\/\/example"/);
    assert.match(html, /data-field="amount"/);
});
