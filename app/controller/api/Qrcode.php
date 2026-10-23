<?php

namespace app\controller\api;

use app\service\PaymentQrAnalyzer;
use app\service\ProcessRunner;
use app\service\QrcodeBatch;
use app\service\QrcodeConflictChanged;
use app\service\QrcodeInput;
use app\service\QrcodeServer;
use app\service\QrcodeMigration;
use app\service\QrcodeCatalog;
use think\facade\Db;
use think\facade\Request;

class Qrcode extends BaseController
{
    public function schema()
    {
        try { return $this->success(QrcodeMigration::status()); }
        catch (\Throwable $e) { return $this->error('无法读取二维码数据库状态'); }
    }

    public function upgrade()
    {
        try { return $this->success(QrcodeMigration::upgrade(), '二维码数据库升级完成'); }
        catch (\Throwable $e) { error_log('QR schema upgrade failed: ' . $e->getMessage()); return $this->error($e->getMessage()); }
    }

    public function updateSettings($id)
    {
        try {
            if (!QrcodeMigration::ready()) throw new \RuntimeException('请先升级二维码数据库');
            $row = Db::name('pay_qrcode')->where('id', QrcodeInput::normalizeId($id))->find();
            if (!$row) return $this->error('二维码不存在', 404);
            $data = QrcodeCatalog::normalize(array_merge($row, Request::param()), (int) $row['type']);
            $limit = filter_var(Request::param('group_limit', 5), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 5) throw new \InvalidArgumentException('参与数量必须为 1 到 5');
            Db::transaction(function () use ($id, $data, $row, $limit): void {
                QrcodeCatalog::lock();
                $others = Db::name('pay_qrcode')->where('type', $row['type'])->where('id', '<>', (int) $id)->select()->toArray();
                QrcodeCatalog::validateRows(array_merge($others, [$data]));
                Db::name('pay_qrcode')->where('id', (int) $id)->update($data);
                Db::execute('INSERT INTO ' . QrcodeMigration::table('setting')
                    . ' (vkey,vvalue) VALUES (?,?) ON DUPLICATE KEY UPDATE vvalue=VALUES(vvalue)',
                    [QrcodeCatalog::limitKey(QrcodeCatalog::group($data)), (string) $limit]);
            });
            return $this->success(null, '二维码设置已保存');
        } catch (\Throwable $e) { return $this->error($e->getMessage()); }
    }
    public function dependencies()
    {
        $runner = new ProcessRunner();
        $timeout = max(1, (int) config('qrcode.command_timeout'));

        return $this->success([
            'zbarimg' => $this->detectDependency($runner, (string) config('qrcode.zbar_binary'), $timeout),
            'tesseract' => $this->detectDependency($runner, (string) config('qrcode.tesseract_binary'), $timeout),
            'opencv' => $this->detectCommandDependency($runner, [
                (string) config('qrcode.python_binary'),
                (string) config('qrcode.opencv_decoder_script'),
                '--version',
            ], $timeout),
            'zxingcpp' => $this->detectCommandDependency($runner, [
                (string) config('qrcode.python_binary'),
                (string) config('qrcode.opencv_decoder_script'),
                '--zxing-version',
            ], $timeout),
            'proc_open' => $runner->isAvailable(),
            'temp_dir_writable' => is_writable(sys_get_temp_dir()),
            'simplexml' => class_exists(\SimpleXMLElement::class),
        ]);
    }

    public function list()
    {
        $requestedType = Request::param('type');
        $type = $requestedType === null ? null : QrcodeInput::normalizeType($requestedType);
        if ($requestedType !== null && $type === null) {
            return $this->error('支付类型错误');
        }

        return $this->paginatedList($type);
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
        $type = QrcodeInput::normalizeType(Request::param('type'));
        if ($type === null) {
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

    public function batchPreview()
    {
        try {
            if (!QrcodeMigration::ready()) return $this->error('请先升级二维码数据库');
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

    public function batchCommit()
    {
        try {
            if (!QrcodeMigration::ready()) return $this->error('请先升级二维码数据库');
            $type = QrcodeInput::normalizeType(Request::param('type'));
            if ($type === null) {
                throw new \InvalidArgumentException('支付类型错误');
            }
            $items = QrcodeBatch::normalizeItems(Request::param('items'));
            $decisions = QrcodeBatch::normalizeDecisions(Request::param('decisions'), $items);
            $submittedToken = trim((string) Request::param('conflict_token'));
            if (!preg_match('/^[a-f0-9]{64}$/', $submittedToken)) {
                throw new \InvalidArgumentException('冲突确认标识无效');
            }

            $result = Db::transaction(function () use ($type, $items, $decisions, $submittedToken): array {
                return $this->withQrWriteLock(function () use ($type, $items, $decisions, $submittedToken): array {
                    $fresh = QrcodeBatch::preview(
                        $type,
                        $items,
                        $this->findExistingByPrices($type, array_column($items, 'price'))
                    );
                    if (!hash_equals($fresh['conflict_token'], $submittedToken)) {
                        throw new QrcodeConflictChanged($fresh);
                    }

                    return $this->applyBatchPlan(
                        $type,
                        QrcodeBatch::commitPlan($fresh, $decisions)
                    );
                });
            });

            return $this->success($result, '二维码批量保存成功');
        } catch (QrcodeConflictChanged $e) {
            return $this->error($e->getMessage(), 409, ['preview' => $e->preview()]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            error_log('QR batch commit failed: ' . $e->getMessage());
            return $this->error('二维码批量保存失败');
        }
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
        try {
            if (!QrcodeMigration::ready()) throw new \RuntimeException('请先升级二维码数据库');
            Db::transaction(function () use ($id): void {
                QrcodeCatalog::lock();
                $row = Db::name('pay_qrcode')->where('id', (int) $id)->find();
                if (!$row) throw new \InvalidArgumentException('二维码不存在');
                $row['price'] = Request::param('price');
                $data = QrcodeCatalog::normalize($row, (int) $row['type']);
                $others = Db::name('pay_qrcode')->where('type', $row['type'])->where('id', '<>', (int) $id)->select()->toArray();
                QrcodeCatalog::validateRows(array_merge($others, [$data]));
                Db::name('pay_qrcode')->where('id', (int) $id)->update($data);
            });
            return $this->success(null, '金额已保存');
        } catch (\Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function bind($id)
    {
        $state = QrcodeInput::normalizeState(Request::param('state'));
        if ($state === null) {
            return $this->error('状态参数只能是 0 或 1');
        }

        try {
            $result = Db::transaction(function () use ($id, $state): string {
                return $this->withQrWriteLock(function () use ($id, $state): string {
                    $qrcode = Db::name('pay_qrcode')->where('id', (int) $id)->find();
                    if (!$qrcode) {
                        return 'missing';
                    }
                    $updated = Db::name('pay_qrcode')->where('id', (int) $id)->update(['state' => $state]);
                    if ($updated === false) {
                        throw new \RuntimeException('State update failed');
                    }
                    return 'ok';
                });
            });
        } catch (\Throwable $e) {
            error_log('QR state update failed: ' . $e->getMessage());
            return $this->error('二维码状态修改失败');
        }

        if ($result === 'missing') {
            return $this->error('二维码不存在', 404);
        }
        return $this->success(['state' => $state], '二维码状态修改成功');
    }

    public function parse()
    {
        $file = Request::file('file');
        if (!$file) return $this->error('请选择二维码图片');
        try {
            $path = (string) $file->getRealPath();
            $image = \app\service\PaymentImage::save($path);
            try {
                $result = (new PaymentQrAnalyzer())->analyze($path);
            } catch (\Throwable $e) {
                $result = ['url' => '', 'amount' => '', 'amount_status' => 'manual', 'candidates' => [],
                    'decoder' => null, 'warning' => '原图已保存，请确认码种类和金额模式'];
            }
            $result['image_path'] = $image;
            return $this->success($result, '图片已保存');
        } catch (\Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function cropReward()
    {
        try {
            $result = (new \app\service\RewardImageCropper())->crop((string) Request::post('image_path', ''));
            return $this->success($result, '图片处理完成');
        } catch (\Throwable $e) {
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
                return response(base64_decode($matches[2]))->header(['Content-Type' => $matches[1]]);
            }

            return $this->success(['qrcode' => $qrcode]);
        } catch (\Throwable $e) {
            return $this->error('生成二维码失败: ' . $e->getMessage());
        }
    }

    private function paginatedList(?int $type)
    {
        $pagination = QrcodeInput::pagination(Request::param('page', 1), Request::param('limit', 12));
        $sort = QrcodeInput::normalizeSort(Request::param('sort', 'newest'));

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
            ->order(QrcodeInput::sortOrder($sort))
            ->page($pagination['page'], $pagination['limit'])
            ->select()
            ->toArray();

        foreach ($items as &$item) {
            $item['id'] = (string) $item['id'];
            $item['group_limit'] = isset($item['amount_mode']) ? QrcodeCatalog::groupLimit(QrcodeCatalog::group($item)) : 5;
            $item['type_text'] = (int) $item['type'] === 1 ? '微信' : '支付宝';
            $item['state_text'] = (int) $item['state'] === 0 ? '正常' : '禁用';
        }
        unset($item);

        return $this->success([
            'total' => (int) $total,
            'items' => $items,
            'page' => $pagination['page'],
            'limit' => $pagination['limit'],
            'sort' => $sort,
        ]);
    }

    private function createForType(int $type)
    {
        try {
            if (!QrcodeMigration::ready()) throw new \RuntimeException('请先升级二维码数据库');
            $data = QrcodeCatalog::normalize(Request::param(), $type);
            $id = Db::transaction(function () use ($data) {
                QrcodeCatalog::lock();
                $rows = Db::name('pay_qrcode')->where('type', $data['type'])->select()->toArray();
                QrcodeCatalog::validateRows(array_merge($rows, [$data]));
                return Db::name('pay_qrcode')->insertGetId($data + ['state' => 0]);
            });
            return $this->success(['id' => (string) $id], '添加二维码成功');
        } catch (\Throwable $e) { return $this->error($e->getMessage()); }
    }

    private function deleteForType(int $id, ?int $type)
    {
        try {
            $result = Db::transaction(function () use ($id, $type): string {
                return $this->withQrWriteLock(function () use ($id, $type): string {
                    $qrcode = Db::name('pay_qrcode')->where('id', $id)->find();
                    if (!$qrcode) {
                        return 'missing';
                    }
                    if ($type !== null && (int) $qrcode['type'] !== $type) {
                        return 'type_mismatch';
                    }
                    if (!Db::name('pay_qrcode')->where('id', $id)->delete()) {
                        throw new \RuntimeException('QR delete failed');
                    }
                    return 'ok';
                });
            });
        } catch (\Throwable $e) {
            error_log('QR delete failed: ' . $e->getMessage());
            return $this->error('删除二维码失败');
        }

        if ($result === 'missing') {
            return $this->error('二维码不存在', 404);
        }
        if ($result === 'type_mismatch') {
            return $this->error('二维码类型不匹配');
        }
        return $this->success(null, '删除二维码成功');
    }

    /**
     * @param array<int,string> $prices
     * @return array<int,array<string,mixed>>
     */
    private function findExistingByPrices(int $type, array $prices): array
    {
        return Db::name('pay_qrcode')->where('type', $type)->order('id asc')->select()->toArray();
    }

    private function applyBatchPlan(int $type, array $plan): array
    {
        $results = [];
        $totals = ['inserted' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($plan as $item) {
            if ($item['action'] === 'skip') {
                $totals['skipped']++;
                $results[] = ['client_id' => $item['client_id'], 'status' => 'skipped'];
                continue;
            }
            $data = QrcodeCatalog::normalize($item, $type);
            if ($item['action'] === 'replace') {
                $id = $item['target_id'];
                Db::name('pay_qrcode')->where('id', $id)->update($data);
                $status = 'replaced';
            } else {
                $id = Db::name('pay_qrcode')->insertGetId($data + ['state' => 0]);
                $status = 'inserted';
            }
            $totals[$status]++;
            $results[] = ['client_id' => $item['client_id'], 'id' => (string) $id, 'status' => $status];
        }
        return ['results' => $results, 'totals' => $totals];
    }



    private function withQrWriteLock(callable $callback): mixed
    {
        $lock = Db::name('setting')->where('vkey', 'user')->lock(true)->find();
        if (!$lock) {
            throw new \RuntimeException('系统设置锁记录不存在');
        }
        return $callback();
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
        } catch (\Throwable) {
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
