"""Suggest a rectangular crop, never decode or redraw a reward code."""
import itertools
import json
import math
import os
import sys

import cv2
import numpy as np


def locate(image):
    height, width = image.shape[:2]
    scale = min(1.0, 1600.0 / max(height, width))
    small = cv2.resize(image, None, fx=scale, fy=scale) if scale < 1 else image
    gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
    binary = cv2.threshold(gray, 110, 255, cv2.THRESH_BINARY_INV)[1]
    contours, hierarchy = cv2.findContours(binary, cv2.RETR_TREE, cv2.CHAIN_APPROX_SIMPLE)
    if hierarchy is None:
        return None
    rings = []
    for index, contour in enumerate(contours):
        child = hierarchy[0][index][2]
        if child < 0:
            continue
        dot = hierarchy[0][child][2]
        if dot < 0:
            continue
        area = cv2.contourArea(contour)
        perimeter = cv2.arcLength(contour, True)
        if area < 30 or not perimeter or 4 * math.pi * area / perimeter ** 2 < 0.82:
            continue
        (x, y), radius = cv2.minEnclosingCircle(contour)
        if radius < 4 or radius > min(small.shape[:2]) * 0.08:
            continue
        hole_area = cv2.contourArea(contours[child])
        dot_area = cv2.contourArea(contours[dot])
        if not (0.25 < hole_area / area < 0.8 and 0.025 < dot_area / hole_area < 0.55):
            continue
        (hx, hy), _ = cv2.minEnclosingCircle(contours[child])
        (dx, dy), _ = cv2.minEnclosingCircle(contours[dot])
        if max(math.hypot(x - hx, y - hy), math.hypot(x - dx, y - dy)) > radius * 0.2:
            continue
        if not any(math.hypot(x - rx, y - ry) < radius for rx, ry, _ in rings):
            rings.append((x, y, radius))
    if not 3 <= len(rings) <= 24:
        return None

    candidates = []
    for triple in itertools.combinations(rings, 3):
        radii = [point[2] for point in triple]
        if max(radii) / min(radii) > 1.4:
            continue
        edges = sorted((math.hypot(triple[a][0] - triple[b][0], triple[a][1] - triple[b][1]), a, b)
                       for a, b in ((0, 1), (0, 2), (1, 2)))
        leg = (edges[0][0] + edges[1][0]) / 2
        if not leg or edges[0][0] / edges[1][0] < 0.85:
            continue
        if abs(edges[2][0] / leg - math.sqrt(2)) > 0.12:
            continue
        if not 9 < leg / (sum(radii) / 3) < 18:
            continue
        a, b = triple[edges[2][1]], triple[edges[2][2]]
        cx, cy = (a[0] + b[0]) / 2, (a[1] + b[1]) / 2
        half = leg * 1.02
        left, top = math.floor(cx - half), math.floor(cy - half)
        right, bottom = math.ceil(cx + half), math.ceil(cy + half)
        if left < 0 or top < 0 or right > gray.shape[1] or bottom > gray.shape[0]:
            continue
        yy, xx = np.nonzero(binary[top:bottom, left:right])
        dx, dy = xx + left - cx, yy + top - cy
        distance = np.hypot(dx, dy)
        annulus = (distance > leg * 0.55) & (distance < leg * 0.9)
        angles = ((np.arctan2(dy[annulus], dx[annulus]) + math.pi) * 24 / (2 * math.pi)).astype(int) % 24
        if len(np.unique(angles)) < 20:
            continue
        # Reject nonblank crop boundaries: never knowingly cut an outer code point.
        region = gray[top:bottom, left:right]
        band = max(3, math.ceil(leg * 0.04))
        border = np.concatenate((region[:band].ravel(), region[-band:].ravel(),
                                 region[:, :band].ravel(), region[:, -band:].ravel()))
        if np.mean(border < 200) > 0.002:
            continue
        bounds = (math.floor(left / scale), math.floor(top / scale),
                  min(width, math.ceil(right / scale)), min(height, math.ceil(bottom / scale)))
        if not any(sum(abs(v - w) for v, w in zip(bounds, old)) < 20 / scale for old in candidates):
            candidates.append(bounds)
    return candidates[0] if len(candidates) == 1 else None


def crop(source, target):
    if not os.path.isfile(source) or os.path.getsize(source) > 10 * 1024 * 1024:
        raise ValueError('invalid_image')
    image = cv2.imdecode(np.fromfile(source, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None or image.shape[0] * image.shape[1] > 40000000:
        raise ValueError('invalid_image')
    bounds = locate(image)
    if bounds is None:
        return {'status': 'original', 'reason': 'uncertain_boundary'}
    left, top, right, bottom = bounds
    # Full-resolution rectangular pixels, with no mask, scaling or recompression loss.
    result = image[top:bottom, left:right]
    ok, encoded = cv2.imencode('.png', result)
    if not ok:
        raise ValueError('encode_failed')
    encoded.tofile(target)
    return {'status': 'cropped', 'bounds': list(bounds), 'width': right - left, 'height': bottom - top}


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3:
            raise ValueError('invalid_arguments')
        print(json.dumps(crop(sys.argv[1], sys.argv[2])))
    except Exception:
        print(json.dumps({'status': 'original', 'reason': 'processing_failed'}))
        sys.exit(1)
