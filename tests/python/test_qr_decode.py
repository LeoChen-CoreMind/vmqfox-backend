import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "qr_decode.py"
FIXTURES = ROOT / "tests" / "fixtures" / "qr"
DENSE_IMAGE = FIXTURES / "wechat-dense-logo.jpg"
REFERENCE_IMAGE = FIXTURES / "wechat-reference.jpg"
DENSE_VALUE = "vmqfox-test://dense-logo/2026?payload=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz&case=high-density&source=public-fixture"
REFERENCE_VALUE = "https://example.com/vmqfox-test/reference?case=standard&version=2026"

try:
    import zxingcpp  # noqa: F401
except Exception:
    ZXING_AVAILABLE = False
else:
    ZXING_AVAILABLE = True


def run_decoder(image, extra_pythonpath=None):
    env = os.environ.copy()
    if extra_pythonpath is not None:
        existing = env.get("PYTHONPATH", "")
        env["PYTHONPATH"] = str(extra_pythonpath) + (os.pathsep + existing if existing else "")

    return subprocess.run(
        [sys.executable, str(SCRIPT), str(image)],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        env=env,
        timeout=20,
        check=False,
    )


class QrDecodeIntegrationTest(unittest.TestCase):
    def assert_decoded(self, result, expected, decoder):
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(expected, result.stdout)
        self.assertIn("decoder=" + decoder, result.stderr)

    def test_reference_image_decodes(self):
        result = run_decoder(REFERENCE_IMAGE)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(REFERENCE_VALUE, result.stdout)
        self.assertRegex(result.stderr, r"decoder=(?:zxingcpp|opencv)")

    @unittest.skipUnless(ZXING_AVAILABLE, "ZXing-C++ is not installed")
    def test_dense_logo_image_decodes_with_zxing(self):
        self.assert_decoded(run_decoder(DENSE_IMAGE), DENSE_VALUE, "zxingcpp")

    def test_unicode_image_path_decodes(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            unicode_path = Path(temp_dir) / "微信二维码.jpg"
            shutil.copyfile(REFERENCE_IMAGE, unicode_path)
            result = run_decoder(unicode_path)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(REFERENCE_VALUE, result.stdout)

    def test_broken_zxing_import_falls_back_to_opencv(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            module_path = Path(temp_dir) / "zxingcpp.py"
            module_path.write_text("raise RuntimeError('broken native module')\n", encoding="utf-8")
            result = run_decoder(REFERENCE_IMAGE, temp_dir)

        self.assert_decoded(result, REFERENCE_VALUE, "opencv")

    def test_broken_zxing_results_fall_back_to_opencv(self):
        module_source = """
class BarcodeFormat:
    QRCode = 1

class BrokenResults:
    def __iter__(self):
        raise RuntimeError('broken result iterator')

def read_barcodes(*args, **kwargs):
    return BrokenResults()
"""
        with tempfile.TemporaryDirectory() as temp_dir:
            module_path = Path(temp_dir) / "zxingcpp.py"
            module_path.write_text(module_source, encoding="utf-8")
            result = run_decoder(REFERENCE_IMAGE, temp_dir)

        self.assert_decoded(result, REFERENCE_VALUE, "opencv")


if __name__ == "__main__":
    unittest.main()
