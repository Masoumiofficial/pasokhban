#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ساخت PDF راهنمای فارسی پاسخ‌بان از فایل مارک‌داون — راست‌به‌چپ، با خط‌شکنی دستی.

    python3 tools/build-guide-pdf.py <guide.md> <out.pdf> [--version 1.0.0] [--font-dir DIR]

چرا خط‌شکنی دستی؟
  ReportLab برای متن فارسی دو حالت دارد: یا واژه‌ها را خودش می‌شکند و ترتیب
  نشانه‌ها (نقطه، پرانتز، لاتین وسط فارسی) غلط درمی‌آید، یا متن را از پیش «مرتب
  بصری» کرده و ما خودمان می‌شکنیم. راه دوم پیاده شده:
     ۱) متن در ترتیب منطقی →  ۲) arabic_reshaper برای شکل حروف
     ۳) خط‌شکنی با اندازه‌گیری واقعیِ فونت  ۴) bidi.get_display برای هر خط
     ۵) Paragraph با <br/> و بدون wordWrap (دیگر شکستن خطوط ندارد)
  برای بازبینی چشمی: pypdfium2 صفحه‌ها را PNG می‌کند (مثال پایین فایل).

فونت: وزیرمتن (TTF). دریافت:
  https://codeload.github.com/rastikerdar/vazirmatn/tar.gz/refs/tags/v33.003
بسته‌ها: reportlab + arabic-reshaper + python-bidi  (و pypdfium2 برای بازبینی)
"""
import argparse
import io
import os
import re
import sys

import arabic_reshaper
from bidi.algorithm import get_display

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.pdfmetrics import registerFontFamily
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table,
                                TableStyle)

BRAND = 'پاسخ\u200cبان'
ACCENT = colors.HexColor('#0d9488')
INK = colors.HexColor('#12242b')
MUTED = colors.HexColor('#5b6b73')
LINE = colors.HexColor('#d7e3e6')
SOFT = colors.HexColor('#f2f8f8')
FONT_DIR_CAND = [
    os.environ.get('PASOKHBAN_FONT_DIR', ''),
    '/home/user/work/vazirmatn-33.003/fonts/ttf',
    '/usr/share/fonts/truetype/vazirmatn',
    os.path.expanduser('~/.fonts'),
]


def pick_fonts(override):
    for d in ([override] if override else []) + FONT_DIR_CAND:
        if d and os.path.exists(os.path.join(d, 'Vazirmatn-Regular.ttf')):
            reg = os.path.join(d, 'Vazirmatn-Regular.ttf')
            bold = os.path.join(d, 'Vazirmatn-Bold.ttf')
            if os.path.exists(bold):
                return reg, bold
    sys.exit('❌ وزیرمتن پیدا نشد؛ با --font-dir پوشۀ TTF را بدهید.')


COVER = None
MISSING = set()


def shape(text):
    text = re.sub(r'\s+', ' ', text).strip()
    if COVER is not None:
        for ch in set(text):
            if ord(ch) > 0x7F and ord(ch) not in COVER:
                MISSING.add(ch)
    return arabic_reshaper.reshape(text)


def wrap_visual(text, font, size, width):
    """متن منطقی → فهرست خطوط با ترتیب بصریِ هر خط."""
    if not text.strip():
        return []
    logical = shape(text)
    words = logical.split(' ')
    lines, cur = [], ''
    for w in words:
        trial = (cur + ' ' + w).strip()
        if pdfmetrics.stringWidth(trial, font, size) <= width or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return [get_display(ln) for ln in lines]


def P(text, style, width, bold=False):
    font = 'Psb-Bold' if bold else 'Psb'
    lines = wrap_visual(text, font, style.fontSize, width)
    if not lines:
        return Spacer(1, 0)
    st = ParagraphStyle('x', parent=style, wordWrap=None)
    return Paragraph('<br/>'.join(lines), st)


# وزیرمتن گلیفِ این نشانه‌ها را ندارد → پیش از رندر جایگزین می‌شوند (وگرنه بی‌صدا غیب می‌شوند)
GLYPH_SUBS = (
    ('\u2190', '\u2039'),   # ←  ›  (نشانۀ breadcrumb)
    ('\u2192', '\u203a'),   # →  ›
    ('\u26a0\ufe0f', '!'),   # ⚠️ → !
    ('\u26a0', '!'),      # ⚠  → !
)


def clean(s):
    s = re.sub(r'\[([^\]]+)\]\((?:[^)]+)\)', r'\1', s)
    s = re.sub(r'`([^`]+)`', r'\1', s)
    s = re.sub(r'\*\*([^*]+)\*\*', r'\1', s)
    for a, b in GLYPH_SUBS:
        s = s.replace(a, b)
    s = s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
    return s


class PsbDoc(SimpleDocTemplate):
    version = ''

    def afterPage(self):
        c = self.canv
        c.saveState()
        c.setFont('Psb', 7.4)
        c.setFillColor(MUTED)
        right = self.leftMargin + self.width
        c.drawRightString(right, self.height + self.bottomMargin + 5.5 * mm,
                          get_display(shape('%s — راهنمای کامل | نسخهٔ %s' % (BRAND, self.version))))
        c.drawCentredString((self.leftMargin + right) / 2, self.bottomMargin - 6 * mm,
                            get_display(str(self.page)))
        c.setStrokeColor(LINE)
        c.setLineWidth(0.4)
        c.line(self.leftMargin, self.height + self.bottomMargin + 2.6 * mm, right,
               self.height + self.bottomMargin + 2.6 * mm)
        c.restoreState()


def build(md_path, out_path, version, date_str, font_dir):
    reg, bold = pick_fonts(font_dir)
    pdfmetrics.registerFont(TTFont('Psb', reg))
    pdfmetrics.registerFont(TTFont('Psb-Bold', bold))
    registerFontFamily('Psb', normal='Psb', bold='Psb-Bold', italic='Psb', boldItalic='Psb-Bold')
    global COVER
    try:
        COVER = set(pdfmetrics.getFont('Psb').face.charToGlyph.keys()) | set(
            pdfmetrics.getFont('Psb-Bold').face.charToGlyph.keys())
    except Exception:
        COVER = None

    W, H = A4
    LM = RM = 16 * mm
    TM = 20 * mm
    BM = 16 * mm
    AW = W - LM - RM      # عرض مفید صفحه

    body = ParagraphStyle('body', fontName='Psb', fontSize=9.3, leading=15.8,
                          alignment=TA_RIGHT, textColor=INK, spaceAfter=2.2)
    li = ParagraphStyle('li', parent=body, rightIndent=0, spaceAfter=1.4)
    h1 = ParagraphStyle('h1', fontName='Psb-Bold', fontSize=17.5, leading=25, alignment=TA_RIGHT,
                        textColor=ACCENT, spaceBefore=8, spaceAfter=4)
    h2 = ParagraphStyle('h2', fontName='Psb-Bold', fontSize=12.2, leading=19, alignment=TA_RIGHT,
                        textColor=INK, spaceBefore=8, spaceAfter=3)
    h3 = ParagraphStyle('h3', fontName='Psb-Bold', fontSize=10.2, leading=16, alignment=TA_RIGHT,
                        textColor=ACCENT, spaceBefore=5.5, spaceAfter=2)
    tiny = ParagraphStyle('tiny', fontName='Psb', fontSize=7.8, leading=12.6, alignment=TA_RIGHT,
                          textColor=MUTED)
    cell = ParagraphStyle('cell', fontName='Psb', fontSize=8.0, leading=12.2, alignment=TA_RIGHT,
                          textColor=INK)
    cellh = ParagraphStyle('cellh', parent=cell, fontName='Psb-Bold', textColor=colors.white)

    story = []
    lines = io.open(md_path, encoding='utf-8').read().split('\n')
    k, n = 0, len(lines)
    first_h1_done = False
    while k < n:
        ln = lines[k].rstrip()
        if not ln.strip() or (set(ln.strip()) <= set('-* ') and ln.strip()):
            k += 1
            continue
        m = re.match(r'^(#{1,4})\s+(.*)$', ln)
        if m:
            lvl, txt = len(m.group(1)), clean(m.group(2))
            if lvl == 1 and not first_h1_done:
                first_h1_done = True   # تیتر اول در جلد آمده؛ تکرار نمی‌شود
                k += 1
                continue
            story.append(P(txt, {1: h1, 2: h2, 3: h3, 4: h3}[lvl], AW, bold=True))
            k += 1
            continue
        if ln.lstrip().startswith('|') and k + 1 < n and re.match(r'^\s*\|[\s:|-]+\|\s*$', lines[k + 1]):
            rows = []
            while k < n and lines[k].lstrip().startswith('|'):
                cells = [c.strip() for c in lines[k].strip().strip('|').split('|')]
                if not re.match(r'^[\s:|-]+$', '|'.join(cells)):
                    rows.append(cells)
                k += 1
            if not rows:
                continue
            ncol = max(len(r) for r in rows)
            rows = [r + [''] * (ncol - len(r)) for r in rows]
            # ستون اولِ عددی/کوتاه باریک‌تر
            w_short = AW * 0.085
            if ncol >= 3:
                widths = [w_short] + [(AW - w_short) / (ncol - 1)] * (ncol - 1)
            else:
                widths = [AW / ncol] * ncol
            data = []
            for i, row in enumerate(rows):
                tr = []
                for j, c in enumerate(row):
                    txt = clean(c)
                    st = cellh if i == 0 else cell
                    tr.append(P(txt, st, widths[j] - 6, bold=(i == 0)))
                data.append(tr)
            t = Table(data, colWidths=widths, repeatRows=1, hAlign='RIGHT')
            stl = [
                ('BACKGROUND', (0, 0), (-1, 0), ACCENT),
                ('GRID', (0, 0), (-1, -1), 0.35, LINE),
                ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                ('TOPPADDING', (0, 0), (-1, -1), 3.0),
                ('BOTTOMPADDING', (0, 0), (-1, -1), 3.0),
                ('LEFTPADDING', (0, 0), (-1, -1), 4),
                ('RIGHTPADDING', (0, 0), (-1, -1), 4),
            ]
            for i in range(1, len(rows)):
                if i % 2 == 0:
                    stl.append(('BACKGROUND', (0, i), (-1, i), SOFT))
            t.setStyle(TableStyle(stl))
            story.append(Spacer(1, 1.4 * mm))
            story.append(t)
            story.append(Spacer(1, 2.4 * mm))
            continue
        m = re.match(r'^\s*(?:[-*]|\d+[.)])\s+(.*)$', ln)
        if m:
            txt = clean(m.group(1))
            k += 1
            # خط‌های ادامه (با تورفتگی) به همان بند فهرست می‌چسبند
            while k < n and re.match(r'^\s{2,}\S', lines[k]) and not re.match(
                    r'^\s*(?:[-*]|\d+[.)])\s+|^\s*\||^\s*>|^(#{1,4}\s|---)', lines[k]):
                txt += ' ' + clean(lines[k].strip())
                k += 1
            dot = Paragraph(get_display(shape('•')), ParagraphStyle(
                'b', parent=li, alignment=TA_CENTER, textColor=ACCENT))
            # برای متن راست‌به‌چپ، نشانۀ گلوله باید در ستونِ سمت راست باشد
            story.append(Table([[P(txt, li, AW - 6 * mm), dot]],
                               colWidths=[AW - 4.2 * mm, 4.2 * mm], hAlign='RIGHT',
                               style=TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'),
                                                 ('LEFTPADDING', (0, 0), (-1, -1), 0),
                                                 ('RIGHTPADDING', (0, 0), (-1, -1), 0),
                                                 ('TOPPADDING', (0, 0), (-1, -1), 0),
                                                 ('BOTTOMPADDING', (0, 0), (-1, -1), 1.4)])))
            continue
        m = re.match(r'^\s*>\s?(.*)$', ln)
        if m:
            buf = [m.group(1).strip()]
            k += 1
            while k < n and re.match(r'^\s*>', lines[k]):
                buf.append(re.sub(r'^\s*>\s?', '', lines[k]).strip())
                k += 1
            story.append(Table([[P(clean(' '.join(buf)), tiny, AW - 12 * mm)]],
                               colWidths=[AW], hAlign='RIGHT',
                               style=TableStyle([('BACKGROUND', (0, 0), (-1, -1), SOFT),
                                                 ('RIGHTPADDING', (0, 0), (-1, -1), 5),
                                                 ('LEFTPADDING', (0, 0), (-1, -1), 5),
                                                 ('TOPPADDING', (0, 0), (-1, -1), 3.4),
                                                 ('BOTTOMPADDING', (0, 0), (-1, -1), 3.4),
                                                 ('LINEBEFORE', (0, 0), (0, -1), 1.6, ACCENT),
                                                 ('VALIGN', (0, 0), (-1, -1), 'TOP')])))
            continue
        buf = [ln.strip()]
        k += 1
        while k < n and lines[k].strip() and not re.match(
                r'^(#{1,4}\s|\s*[-*|]|\s*\d+[.)]\s|\s*>|---)', lines[k]):
            buf.append(lines[k].strip())
            k += 1
        story.append(P(clean(' '.join(buf)), body, AW))

    cover = [
        Spacer(1, 26 * mm),
        P(BRAND, ParagraphStyle('t', fontName='Psb-Bold', fontSize=32, leading=42,
                                alignment=TA_CENTER, textColor=ACCENT), AW, bold=True),
        Spacer(1, 2.5 * mm),
        P('راهنمای کامل نصب، راه‌اندازی و عیب\u200cیابی',
          ParagraphStyle('s', fontName='Psb', fontSize=12.6, leading=20, alignment=TA_CENTER,
                         textColor=INK), AW),
        Spacer(1, 6 * mm),
        P('نسخۀ %s — نسخۀ لانچ' % version,
          ParagraphStyle('v', fontName='Psb-Bold', fontSize=10.6, leading=17, alignment=TA_CENTER,
                         textColor=MUTED), AW, bold=True),
        P('اتحاد وردپرس — etehadwp.com — %s' % date_str,
          ParagraphStyle('v2', parent=tiny, alignment=TA_CENTER), AW),
        Spacer(1, 5 * mm),
        P('این سند توضیحی است و بخشی از قرارداد لایسنس محسوب نمی‌شود. شمارهٔ نسخه و '
          'پیش\u200cفرض\u200cهای نوشته\u200cشده در آن با بستۀ نصب هم‌خوان است.',
          ParagraphStyle('n', parent=body, alignment=TA_CENTER, textColor=MUTED), AW * 0.86),
        PageBreak(),
    ]
    doc = PsbDoc(out_path, pagesize=A4, leftMargin=LM, rightMargin=RM, topMargin=TM,
                 bottomMargin=BM, title='راهنمای پاسخ‌بان ' + version, author='اتحاد وردپرس')
    doc.version = version
    doc.build(cover + story)
    if MISSING:
        print('⚠️  %d کاراکتر بدون گلیف (GLYPH_SUBS را کامل کنید): %s'
              % (len(MISSING), ' '.join('%s(U+%04X)' % (c, ord(c)) for c in sorted(MISSING))))
    return out_path


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('md')
    ap.add_argument('out')
    ap.add_argument('--version', default='1.0.0')
    ap.add_argument('--date', default='۱۴۰۵')
    ap.add_argument('--font-dir', default='')
    a = ap.parse_args()
    out = build(a.md, a.out, a.version, a.date, a.font_dir or None)
    print('✅ PDF ساخته شد: %s (%s بایت)' % (out, format(os.path.getsize(out), ',')))
    print('   بازبینی چشمی:  python3 - <<PY ... import pypdfium2; '
          'pdfium.PdfDocument(r"%s")[0].render(scale=1.4).to_pil().save("pg1.png")' % out)
