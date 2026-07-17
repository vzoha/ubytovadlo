/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

/**
 * Obrazová část čtení MRZ z fotky dokladu: převod do šedi, prahování (Otsu i
 * adaptivní), doostření, detekce pásu MRZ a výřez nalezených řádků.
 *
 * Čistě pixelová matematika nad Canvasem — nevolá OCR ani nesahá na stránku,
 * takže ji stejně používá check-in hosta i vývojářský test nad korpusem.
 * Navazující kroky (OCR průchody, hlasování, ladicí výpisy) si drží každá
 * stránka vlastní, protože se liší.
 */
window.MrzImage = (function () {
    'use strict';

    function toGrey(data) {
        var n = data.length / 4;
        var grey = new Uint8ClampedArray(n);
        for (var i = 0, p = 0; i < n; i++, p += 4) {
            grey[i] = (0.299 * data[p] + 0.587 * data[p + 1] + 0.114 * data[p + 2]) | 0;
        }
        return grey;
    }

    function otsuFromGrey(grey) {
        var hist = new Array(256);
        for (var i = 0; i < 256; i++) hist[i] = 0;
        for (var p = 0; p < grey.length; p++) hist[grey[p]]++;
        var n = grey.length;
        var sum = 0;
        for (var t = 0; t < 256; t++) sum += t * hist[t];
        var sumB = 0, wB = 0, maxV = 0, thr = 127;
        for (var u = 0; u < 256; u++) {
            wB += hist[u];
            if (wB === 0) continue;
            var wF = n - wB;
            if (wF === 0) break;
            sumB += u * hist[u];
            var mB = sumB / wB;
            var mF = (sum - sumB) / wF;
            var between = wB * wF * (mB - mF) * (mB - mF);
            if (between > maxV) { maxV = between; thr = u; }
        }
        return thr;
    }

    function applyThreshold(data, mask) {
        for (var i = 0, p = 0; i < mask.length; i++, p += 4) {
            var v = mask[i];
            data[p] = data[p + 1] = data[p + 2] = v;
        }
    }

    function otsuBinarize(data) {
        var grey = toGrey(data);
        var thr = otsuFromGrey(grey);
        var mask = new Uint8ClampedArray(grey.length);
        for (var i = 0; i < grey.length; i++) mask[i] = grey[i] < thr ? 0 : 255;
        applyThreshold(data, mask);
    }

    function adaptiveBinarize(data, w, h, windowPx, cFactor) {
        var grey = toGrey(data);
        var ii = new Float64Array(w * h);
        for (var y = 0; y < h; y++) {
            var row = 0;
            for (var x = 0; x < w; x++) {
                var idx = y * w + x;
                row += grey[idx];
                ii[idx] = (y > 0 ? ii[idx - w] : 0) + row;
            }
        }
        var half = (windowPx / 2) | 0;
        var mask = new Uint8ClampedArray(grey.length);
        for (var y2 = 0; y2 < h; y2++) {
            var y1 = y2 - half; if (y1 < 0) y1 = 0;
            var y3 = y2 + half; if (y3 >= h) y3 = h - 1;
            for (var x2 = 0; x2 < w; x2++) {
                var x1 = x2 - half; if (x1 < 0) x1 = 0;
                var x3 = x2 + half; if (x3 >= w) x3 = w - 1;
                var area = (x3 - x1 + 1) * (y3 - y1 + 1);
                var sum = ii[y3 * w + x3];
                if (x1 > 0) sum -= ii[y3 * w + (x1 - 1)];
                if (y1 > 0) sum -= ii[(y1 - 1) * w + x3];
                if (x1 > 0 && y1 > 0) sum += ii[(y1 - 1) * w + (x1 - 1)];
                var mean = sum / area;
                var thr = mean - cFactor;
                mask[y2 * w + x2] = grey[y2 * w + x2] < thr ? 0 : 255;
            }
        }
        applyThreshold(data, mask);
    }

    function detectMrzBand(data, w, h) {
        var transitions = new Array(h);
        for (var y = 0; y < h; y++) {
            var base = y * w * 4;
            var prev = data[base];
            var t = 0;
            for (var x = 1; x < w; x++) {
                var cur = data[base + x * 4];
                if (cur !== prev) { t++; prev = cur; }
            }
            transitions[y] = t;
        }
        var minT = Math.max(40, w * 0.045);
        var lines = [];
        var start = -1;
        for (var y2 = 0; y2 < h; y2++) {
            if (transitions[y2] >= minT) {
                if (start === -1) start = y2;
            } else if (start !== -1) {
                lines.push({s: start, e: y2 - 1});
                start = -1;
            }
        }
        if (start !== -1) lines.push({s: start, e: h - 1});
        var lower = lines.filter(function(l) { return l.s > h * 0.35 && (l.e - l.s) >= 3; });
        if (lower.length === 0) return null;
        lower.sort(function(a, b) { return a.s - b.s; });
        var bottom = lower[lower.length - 1];
        var bottomH = bottom.e - bottom.s;
        var picked = [bottom];
        for (var i = lower.length - 2; i >= 0; i--) {
            if (picked.length >= 3) break;
            var line = lower[i];
            var gap = picked[0].s - line.e;
            var lineH = line.e - line.s;
            var heightRatio = Math.abs(lineH - bottomH) / Math.max(bottomH, 1);
            if (gap <= bottomH * 2.5 && gap >= 0 && heightRatio < 0.6) {
                picked.unshift(line);
            } else {
                break;
            }
        }
        if (picked.length < 2) return null;
        var s = picked[0].s;
        var e = picked[picked.length - 1].e;
        if (s > h * 0.92) return null;
        if ((e - s) < h * 0.025) return null;
        return { s: s, e: e, lines: picked, lineHeight: bottomH };
    }

    function cloneCanvas(src) {
        var c = document.createElement('canvas');
        c.width = src.width; c.height = src.height;
        c.getContext('2d').drawImage(src, 0, 0);
        return c;
    }

    function sharpenCanvas(canvas) {
        var ctx = canvas.getContext('2d');
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var w = canvas.width, h = canvas.height;
        var grey = toGrey(img.data);
        var out = new Uint8ClampedArray(grey.length);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var idx = y * w + x;
                if (x === 0 || y === 0 || x === w - 1 || y === h - 1) { out[idx] = grey[idx]; continue; }
                var v = 5 * grey[idx] - grey[idx - 1] - grey[idx + 1] - grey[idx - w] - grey[idx + w];
                out[idx] = v < 0 ? 0 : (v > 255 ? 255 : v);
            }
        }
        applyThreshold(img.data, out);
        ctx.putImageData(img, 0, 0);
    }

    function binarizeCanvas(canvas, mode) {
        var ctx = canvas.getContext('2d');
        var data = ctx.getImageData(0, 0, canvas.width, canvas.height);
        if (mode === 'otsu') {
            otsuBinarize(data.data);
        } else if (mode === 'adaptive') {
            // Local window ≈ a third of a glyph height. The band holds ~3
            // MRZ rows, so one glyph ≈ canvas.height / 4; a sub-glyph window
            // (~canvas.height / 12, like ImageMagick -lat 25 on a 3000-px
            // band) keeps thin strokes (the openings in S vs B) instead of
            // flooding them as a near-global threshold would.
            var win = Math.max(15, Math.floor(canvas.height / 12));
            if (win % 2 === 0) win++;
            adaptiveBinarize(data.data, canvas.width, canvas.height, win, 12);
        }
        ctx.putImageData(data, 0, 0);
    }

    function adaptWin(c) { return Math.max(15, Math.floor(c.width / 100)); }

    function binAdaptW(canvas) {
        var win = adaptWin(canvas); if (win % 2 === 0) win++;
        var ctx = canvas.getContext('2d'), d = ctx.getImageData(0, 0, canvas.width, canvas.height);
        adaptiveBinarize(d.data, canvas.width, canvas.height, win, 12); ctx.putImageData(d, 0, 0);
    }

    function mrzClean(t) { return (t || '').toUpperCase().replace(/[^A-Z0-9<]/g, ''); }

    function isAnchor(t) { var c = mrzClean(t); return c.length >= 15 && (c.match(/</g) || []).length >= 2; }

    function isNumericMrz(t) { var c = mrzClean(t); if (c.length < 14) return false; return (c.match(/[0-9]/g) || []).length / c.length >= 0.45; }

    function bottomCluster(L) {
        if (L.length < 2) return null;
        L.sort(function(a, b) { return a.b.y0 - b.b.y0; });
        var cl = [L[L.length - 1]];
        for (var i = L.length - 2; i >= 0; i--) {
            var lh = cl[0].b.y1 - cl[0].b.y0, gap = cl[0].b.y0 - L[i].b.y1;
            if (gap < lh * 1.6 && gap > -lh * 0.5) { cl.unshift(L[i]); } else break;
        }
        return cl.length >= 2 ? cl : null;
    }

    function recropBlock(img, cl, sc) {
        var y0 = cl[0].b.y0 / sc, y1 = cl[cl.length - 1].b.y1 / sc;
        var xs0 = cl.map(function(p) { return p.b.x0 / sc; }).sort(function(a, b) { return a - b; });
        var xs1 = cl.map(function(p) { return p.b.x1 / sc; }).sort(function(a, b) { return a - b; });
        var x0 = xs0[Math.floor(xs0.length / 2)], x1 = xs1[Math.floor(xs1.length / 2)];
        var padY = (y1 - y0) * 0.16, padX = (x1 - x0) * 0.02;
        y0 = Math.max(0, y0 - padY); y1 = Math.min(img.height, y1 + padY);
        x0 = Math.max(0, x0 - padX); x1 = Math.min(img.width, x1 + padX);
        var sw = x1 - x0, s2 = Math.min(4, 3000 / sw);
        var bc = document.createElement('canvas'); bc.width = Math.floor(sw * s2); bc.height = Math.floor((y1 - y0) * s2);
        var bx = bc.getContext('2d'); bx.imageSmoothingEnabled = true; bx.imageSmoothingQuality = 'high';
        bx.drawImage(img, x0, y0, sw, y1 - y0, 0, 0, bc.width, bc.height);
        return bc;
    }

    return {
        toGrey: toGrey,
        otsuFromGrey: otsuFromGrey,
        applyThreshold: applyThreshold,
        otsuBinarize: otsuBinarize,
        adaptiveBinarize: adaptiveBinarize,
        detectMrzBand: detectMrzBand,
        cloneCanvas: cloneCanvas,
        sharpenCanvas: sharpenCanvas,
        binarizeCanvas: binarizeCanvas,
        adaptWin: adaptWin,
        binAdaptW: binAdaptW,
        mrzClean: mrzClean,
        isAnchor: isAnchor,
        isNumericMrz: isNumericMrz,
        bottomCluster: bottomCluster,
        recropBlock: recropBlock
    };
})();
