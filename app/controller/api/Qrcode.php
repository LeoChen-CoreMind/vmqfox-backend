<?php

namespace app\controller\api;

use app\service\PaymentQrAnalyzer;
use app\service\ProcessRunner;
use app\service\QrcodeInput;
use app\service\QrcodeServer;
use think\facade\Db;
use think\facade\Request;

class Qrcode extends BaseController
{
    public function dependencies()
    {
        $runner = new ProcessRunner();
        $timeout = max(1, (int) config('qrcode.command_timeout'));

        return $this->success([
            'zbarimg' => $this->detectDependency(
                $runner,
                (string) config('qrcode.zbar_binary'),
                $timeout
            ),
            'tesseract' => $this->detectDependency(
                $runner,
                (string) config('qrcode.tesseract_binary'),
                $timeout
            ),
            'opencv' => $this->detectCommandDependency(
                $runner,
                [
                    (string) config('qrcode.python_binary'),
                    (string) config('qrcode.opencv_decoder_script'),
                    '--version',
                ],
                $timeout
            ),
            'zxingcpp' => $this->detectCommandDependency(
                $runner,
                [
                    (string) config('qrcode.python_binary'),
                    (string) config('qrcode.opencv_decoder_script'),
                    '--zxing-version',
                ],
                $timeout
            ),
            'proc_open' => $runner->isAvailable(),
            'temp_dir_writable' => is_writable(sys_get_temp_dir()),
            'simplexml' => class_exists(\SimpleXMLElement::class),
        ]);
    }

    public function list()
    {
        $type = Request::param('type');
        if ($type !== null && !in_array((int) $type, [1, 2], true)) {
            return $this->error('支付类型错误');
        }

        return $this->paginatedList($type === null ? null : (int) $type);
    }

    public function wechat()
    {
        return $this->paginatedList(1);
    }

    public function alipay()
    {
        return $this->paginatedList(2);
    }

    public function add()
    {
        $type = (int) Request::param('type');
        if (!in_array($type, [1, 2], true)) {
            return $this->error('支付类型错误');
        }

        return $this->createForType($type);
    }

    public function addWechat()
    {
        return $this->createForType(1);
    }

    public function addAlipay()
    {
        return $this->createForType(2);
    }

    public function delete($id)
    {
        return $this->deleteForType((int) $id, null);
    }

    public function deleteByRequest()
    {
        $id = QrcodeInput::normalizeId(Request::param('id'));
        if ($id === null) {
            return $this->error('二维码 ID 无效');
        }

        return $this->deleteForType($id, null);
    }

    public function deleteWechat($id)
    {
        return $this->deleteForType((int) $id, 1);
    }

    public function deleteAlipay($id)
    {
        return $this->deleteForType((int) $id, 2);
    }

    public function updateAmount($id)
    {
        $price = QrcodeInput::normalizePrice(Request::param('price'));
        if ($price === null) {
            return $this->error('金额必须大于 0，且最多保留两位小数');
        }

        $qrcode = Db::name('pay_qrcode')->where('id', (int) $id)->find();
        if (!$qrcode) {
            return $this->error('二维码不存在', 404);
        }

        Db::name('pay_qrcode')->where('id', (int) $id)->update(['price' => $price]);
        return $this->success(['price' => $price], '金额修改成功');
    }

    public function bind($id)
    {
        $state = QrcodeInput::normalizeState(Request::param('state'));
        if ($state === null) {
            return $this->error('状态参数只能是 0 或 1');
        }

        $qrcode = Db::name('pay_qrcode')->where('id', (int) $id)->find();
        if (!$qrcode) {
            return $this->error('二维码不存在', 404);
        }

        Db::name('pay_qrcode')->where('id', (int) $id)->update(['state' => $state]);
        return $this->success(['state' => $state], '二维码状态修改成功');
    }

    public function parse()
    {
        $file = Request::file('file');
        if (!$file) {
            return $this->error('请选择要识别的二维码图片');
        }

        try {
            $result = (new PaymentQrAnalyzer())->analyze((string) $file->getRealPath());
            return $this->success($result, '识别完成');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            error_log('Payment QR analysis failed: ' . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }

    public function generate()
    {
        $url = Request::param('url');
        if (empty($url)) {
            return $this->error('URL 参数不能为空');
        }

        try {
            $qrcode = (new QrcodeServer())->createQrcode($url);
            if (preg_match('/^data:(image\/[A-Za-z0-9.+-]+);base64,(.+)$/', $qrcode, $matches)) {
                return response(base64_decode($matches[2]))
                    ->header(['Content-Type' => $matches[1]]);
            }

            return $this->success(['qrcode' => $qrcode]);
        } catch (\Throwable $e) {
            return $this->error('生成二维码失败: ' . $e->getMessage());
        }
    }

    private function paginatedList(?int $type)
    {
        $pagination = QrcodeInput::pagination(
            Request::param('page', 1),
            Request::param('limit', 12)
        );

        $query = Db::name('pay_qrcode');
        if ($type !== null) {
            $query->where('type', $type);
        }
        $total = $query->count();

        $query = Db::name('pay_qrcode');
        if ($type !== null) {
            $query->where('type', $type);
        }
        $items = $query
            ->page($pagination['page'], $pagination['limit'])
            ->order('id desc')
            ->select()
            ->toArray();

        foreach ($items as &$item) {
            $item['id'] = (string) $item['id'];
            $item['type_text'] = (int) $item['type'] === 1 ? '微信' : '支付宝';
            $item['state_text'] = (int) $item['state'] === 0 ? '正常' : '禁用';
        }
        unset($item);

        return $this->success([
            'total' => (int) $total,
            'items' => $items,
            'page' => $pagination['page'],
            'limit' => $pagination['limit'],
        ]);
    }

    private function createForType(int $type)
    {
        $payUrl = trim((string) Request::param('pay_url'));
        if ($payUrl === '') {
            return $this->error('收款码内容不能为空');
        }

        $price = QrcodeInput::normalizePrice(Request::param('price'));
        if ($price === null) {
            return $this->error('金额必须大于 0，且最多保留两位小数');
        }

        $id = Db::name('pay_qrcode')->insertGetId([
            'type' => $type,
            'pay_url' => $payUrl,
            'price' => $price,
            'state' => 0,
        ]);
        if (!$id) {
            return $this->error('添加二维码失败');
        }

        return $this->success(['id' => $id], '添加二维码成功');
    }

    private function deleteForType(int $id, ?int $type)
    {
        $qrcode = Db::name('pay_qrcode')->where('id', $id)->find();
        if (!$qrcode) {
            return $this->error('二维码不存在', 404);
        }
        if ($type !== null && (int) $qrcode['type'] !== $type) {
            return $this->error('二维码类型不匹配');
        }

        $deleted = Db::name('pay_qrcode')->where('id', $id)->delete();
        if (!$deleted) {
            return $this->error('删除二维码失败');
        }

        return $this->success(null, '删除二维码成功');
    }

    /**
     * @return array{available:bool,version:?string}
     */
    private function detectDependency(ProcessRunner $runner, string $binary, int $timeoutSeconds): array
    {
        return $this->detectCommandDependency($runner, [$binary, '--version'], $timeoutSeconds);
    }

    /**
     * @param array<int,string> $command
     * @return array{available:bool,version:?string}
     */
    private function detectCommandDependency(ProcessRunner $runner, array $command, int $timeoutSeconds): array
    {
        if (!$runner->isAvailable()) {
            return ['available' => false, 'version' => null];
        }

        try {
            $result = $runner->run($command, $timeoutSeconds);
        } catch (\Throwable $e) {
            return ['available' => false, 'version' => null];
        }

        if ($result['timed_out'] || $result['exit_code'] !== 0) {
            return ['available' => false, 'version' => null];
        }

        $output = trim($result['stdout'] . "\n" . $result['stderr']);
        preg_match('/\b\d+(?:\.\d+)+\b/', $output, $matches);

        return ['available' => true, 'version' => $matches[0] ?? null];
    }
}
