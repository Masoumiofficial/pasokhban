#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""تولید لوگوی «گپینه»: نشانه + لوک‌آپ افقی (SVG خودبسنده، بدون فونت).

    pip install uharfbuzz fonttools
    VAZIRMATN_TTF=/path/to/ttf LOGO_OUT=/path/to/out python3 tools/build-logo.py

حروف با HarfBuzz شکل می‌گیرند (چسبیدن فارسی) و به مسیر SVG تبدیل می‌شوند؛
خروجی به هیچ فونت نصب‌شده‌ای وابسته نیست. فونت: وزیرمتن، SIL OFL 1.1.
"""
import os
import uharfbuzz as hb
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.pens.boundsPen import BoundsPen
from fontTools.misc.transform import Transform

OUT = os.environ.get('LOGO_OUT', os.getcwd())
FONT_DIR = os.environ.get('VAZIRMATN_TTF',
                          os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                       '..', 'vendor', 'vazirmatn'))
FONT = os.path.join(FONT_DIR, 'Vazirmatn-%s.ttf')
CYAN, INDIGO, DARK, MUTED = '#22d3ee', '#6366f1', '#0f172a', '#475569'
WORD = 'گپینه'


# ---------- شکل‌دهی متن (چسبیدن حروف) ----------
def shaped_run(text, weight='Bold'):
    """خروجی: [(glyph_name, x, y)] در واحد فونت + عرض کل، با شکل‌دهی صحیح فارسی."""
    path = FONT % weight
    blob = hb.Blob.from_file_path(path)
    face = hb.Face(blob)
    font = hb.Font(face)
    buf = hb.Buffer()
    buf.add_str(text)
    buf.direction = 'rtl'
    buf.script = 'Arab'
    buf.language = 'fa'
    hb.shape(font, buf, {'kern': True, 'liga': True})
    order = TTFont(path).getGlyphOrder()
    pos = buf.glyph_positions
    total = sum(p.x_advance for p in pos)
    cursor = 0.0
    out = []
    for info, p in zip(buf.glyph_infos, pos):
        # هارف‌باز برای RTL ترتیب دیداری (چپ به راست) می‌دهد؛ قلم به جلو می‌رود.
        out.append((order[info.codepoint], cursor + p.x_offset, p.y_offset))
        cursor += p.x_advance
    # از راست به چپ ذخیره شده؛ برای مسیر اهمیتی ندارد
    return out, total


def run_path(run, weight='Bold', scale=1.0, dx=0.0, dy=0.0):
    """مسیر SVG برای یک رانِ شکل‌داده‌شده با مقیاس/جابه‌جایی."""
    f = TTFont(FONT % weight)
    gs = f.getGlyphSet()
    parts, boxes = [], []
    for name, x, y in run:
        t = Transform(scale, 0, 0, -scale, dx + x * scale, dy - y * scale)
        pen = SVGPathPen(gs)
        gs[name].draw(TransformPen(pen, t))
        parts.append(pen.getCommands())
        bp = BoundsPen(gs)
        gs[name].draw(TransformPen(bp, t))
        if bp.bounds:
            boxes.append(bp.bounds)
    x0 = min(b[0] for b in boxes)
    y0 = min(b[1] for b in boxes)
    x1 = max(b[2] for b in boxes)
    y1 = max(b[3] for b in boxes)
    return ' '.join(parts), (x0, y0, x1, y1)


# ---------- نشانه ----------
def mark_group(size=64, mono=None, tail=True, radius=18.0):
    """حبابِ گوشه‌گرد با «گ» — خروجی: (svg_inner, gradient_defs)."""
    defs = ''
    if mono:
        bg, glyph = mono, '#ffffff'
    else:
        bg, glyph = 'url(#g)', '#ffffff'
        defs = ('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
                f'<stop offset="0" stop-color="{CYAN}"/>'
                f'<stop offset="1" stop-color="{INDIGO}"/></linearGradient></defs>')
    k = size / 64.0
    body = (f'<rect x="{2*k:.2f}" y="{2*k:.2f}" width="{60*k:.2f}" height="{60*k:.2f}" '
            f'rx="{radius*k:.2f}" fill="{bg}"/>')
    if tail:
        body += (f'<path d="M{47*k:.2f} {47*k:.2f} L{64.5*k:.2f} {64.5*k} '
                 f'L{38*k:.2f} {60*k:.2f} Z" fill="{bg}" stroke="{bg}" '
                 f'stroke-width="{3.4*k:.2f}" stroke-linejoin="round"/>')
    gpath = place_glyph('گ', 'Black', 31 * k, 32 * k, 31 * k)
    body += f'<path d="{gpath}" fill="{glyph}"/>'
    return body, defs


def place_glyph(char, weight, size, cx, cy):
    f = TTFont(FONT % weight)
    gs = f.getGlyphSet()
    gname = f.getBestCmap()[ord(char)]
    bp = BoundsPen(gs)
    gs[gname].draw(bp)
    x0, y0, x1, y1 = bp.bounds
    w, h = x1 - x0, y1 - y0
    s = size / h
    t = Transform(s, 0, 0, -s, cx - (x0 + w / 2) * s, cy + (y0 + h / 2) * s)
    pen = SVGPathPen(gs)
    gs[gname].draw(TransformPen(pen, t))
    return pen.getCommands()


def mark_svg(size=64, mono=None, tail=True):
    body, defs = mark_group(size, mono, tail)
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" '
            f'width="{size}" height="{size}" role="img" aria-label="گپینه">'
            f'<title>گپینه</title>{defs}{body}</svg>\n')


# ---------- لوک‌آپ افقی ----------
def baseline_path(text, weight='Bold'):
    """مسیر متن با خط پایه در y=0، شروع از x=0؛ ارتفاع مثبت رو به پایین."""
    run, total = shaped_run(text, weight)
    f = TTFont(FONT % weight)
    gs = f.getGlyphSet()
    parts, boxes = [], []
    for name, x, y in run:
        t = Transform(1, 0, 0, -1, x, -y)
        pen = SVGPathPen(gs)
        gs[name].draw(TransformPen(pen, t))
        parts.append(pen.getCommands())
        bp = BoundsPen(gs)
        gs[name].draw(TransformPen(bp, t))
        if bp.bounds:
            boxes.append(bp.bounds)
    x0 = min(b[0] for b in boxes)
    y0 = min(b[1] for b in boxes)
    x1 = max(b[2] for b in boxes)
    y1 = max(b[3] for b in boxes)
    # نرمال‌سازی به مبدأ
    parts_n = []
    for name, x, y in run:
        t = Transform(1, 0, 0, -1, x - x0, -y - y0)
        pen = SVGPathPen(gs)
        gs[name].draw(TransformPen(pen, t))
        parts_n.append(pen.getCommands())
    return ' '.join(parts_n), (x1 - x0), (y0 - y0, y1 - y0)


def horizontal_svg(name, word_color=DARK, mark_px=52.0, word_h=26.0, gap=13.0,
                   pad=3.0, tagline=None, tag_color=MUTED):
    wpath, ww, (wy0, wy1) = baseline_path(WORD, 'Bold')
    sw = word_h / (wy1 - wy0)

    tpath = tw = th = None
    if tagline:
        tpath, tw, (ty0, ty1) = baseline_path(tagline, 'Regular')
        st = 11.0 / (ty1 - ty0)
        th = ty1 - ty0

    body_w = pad + ww * sw + gap + mark_px + pad
    H = pad * 2 + mark_px + (th * st + 6 if tagline else 0)
    if tagline:
        body_w = max(body_w, pad * 2 + tw * st + mark_px * 0.6)

    mark_inner, defs = mark_group(64, None, True)
    mx = pad + ww * sw + gap
    my = pad
    inner = f'<g transform="translate({mx:.2f} {my:.2f}) scale({mark_px/64:.5f})">{mark_inner}</g>'

    # واژه‌نشان، چسبیده به نشانه (سمت راست) و هم‌مرکز عمودی با آن
    wy = pad + mark_px / 2 - (wy0 + wy1) / 2 * sw
    inner += (f'<g transform="translate({pad:.2f} {wy:.2f}) scale({sw:.5f})">'
              f'<path d="{wpath}" fill="{word_color}"/></g>')

    if tagline:
        ty = pad + mark_px + 6
        inner += (f'<g transform="translate({body_w - pad - tw*st:.2f} {ty:.2f}) scale({st:.5f})">'
                  f'<path d="{tpath}" fill="{tag_color}"/></g>')

    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {body_w:.2f} {H:.2f}" '
           f'width="{body_w:.2f}" height="{H:.2f}" role="img" aria-label="گپینه">'
           f'<title>گپینه</title>{defs}{inner}</svg>\n')
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as fh:
        fh.write(svg)
    return name, round(body_w, 2), round(H, 2)


if __name__ == '__main__':
    open(os.path.join(OUT, 'brand-mark.svg'), 'w', encoding='utf-8').write(mark_svg())
    open(os.path.join(OUT, 'brand-mark-mono.svg'), 'w', encoding='utf-8').write(mark_svg(mono='#0f172a'))
    open(os.path.join(OUT, 'brand-mark-white.svg'), 'w', encoding='utf-8').write(mark_svg(mono='#ffffff'))
    print(horizontal_svg('logo-horizontal.svg'))
    print(horizontal_svg('logo-horizontal-light.svg', word_color='#ffffff', tag_color='#c7d2fe'))
    print(horizontal_svg('logo-horizontal-tagline.svg', tagline='چت هوشمند و پشتیبانی آنلاین'))
