#!/usr/bin/env python3
import importlib.metadata
import sys

import cv2
import numpy as np

try:
    import zxingcpp
except Exception:
    zxingcpp = None


def read_image(path):
    try:
        encoded = np.fromfile(path, dtype=np.uint8)
    except OSError:
        return None
    if encoded.size == 0:
        return None
    return cv2.imdecode(encoded, cv2.IMREAD_COLOR)


def decode_zxing(image):
    if zxingcpp is None:
        return ""

    try:
        results = zxingcpp.read_barcodes(
            image,
            formats=zxingcpp.BarcodeFormat.QRCode,
        )
        for result in results:
            value = str(result.text or "").strip()
            if value:
                return value
    except Exception:
        return ""

    return ""


def decode(detector, image):
    value, points, _ = detector.detectAndDecode(image)
    if value:
        return value.strip()

    try:
        ok, values, _, _ = detector.detectAndDecodeMulti(image)
        if ok:
            for candidate in values:
                if candidate:
                    return candidate.strip()
    except cv2.error:
        pass

    try:
        value, points, _ = detector.detectAndDecodeCurved(image)
        if value:
            return value.strip()
    except cv2.error:
        pass

    return ""


def variants(image):
    height, width = image.shape[:2]
    longest = max(height, width)
    yield image

    if longest < 1400:
        scale = 1400 / longest
        yield cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    elif longest > 2400:
        scale = 2400 / longest
        yield cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    yield gray
    yield cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
    yield cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]
    yield cv2.adaptiveThreshold(
        gray,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        31,
        5,
    )

    yield cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)
    yield cv2.rotate(image, cv2.ROTATE_180)
    yield cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)


def main():
    if len(sys.argv) == 2 and sys.argv[1] == "--version":
        print(cv2.__version__)
        return 0
    if len(sys.argv) == 2 and sys.argv[1] == "--zxing-version":
        if zxingcpp is None:
            print("ZXing-C++ is not installed", file=sys.stderr)
            return 1
        try:
            print(importlib.metadata.version("zxing-cpp"))
        except importlib.metadata.PackageNotFoundError:
            print("available")
        return 0
    if len(sys.argv) != 2:
        print("Usage: qr_decode.py <image>", file=sys.stderr)
        return 2

    cv2.setNumThreads(1)
    image = read_image(sys.argv[1])
    if image is None:
        print("Unable to read image", file=sys.stderr)
        return 2

    value = decode_zxing(image)
    if value:
        print("decoder=zxingcpp", file=sys.stderr)
        sys.stdout.write(value)
        return 0

    detector = cv2.QRCodeDetector()
    for candidate in variants(image):
        value = decode(detector, candidate)
        if value:
            print("decoder=opencv", file=sys.stderr)
            sys.stdout.write(value)
            return 0

    return 3


if __name__ == "__main__":
    raise SystemExit(main())
