"""
MCPress — generador de marca.
Produce todos los SVG (estáticos y animados) a partir de geometría y fuentes reales
(Poppins ExtraBold + IBM Plex Mono, contorneadas: los SVG no dependen de fuentes).

Uso:  python3 build_brand.py  [out_dir]
Requiere: fonttools, y las fuentes de npm @fontsource/poppins y @fontsource/ibm-plex-mono.
"""
import os, sys, json
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'out', 'brand')

# ───────────────────────── Tokens ─────────────────────────
C = {
    'ink':        '#0D1A2E',   # Tinta  (navy TBA)
    'paper':      '#F4F1EA',   # Papel  (crema TBA)
    'signal':     '#415BF7',   # Señal  (azul eléctrico TBA)
    'glow':       '#7B8DFF',   # Señal sobre oscuro / destello
    'signalDeep': '#2E44D6',   # Señal presionada (hover/active)
    'muted':      '#47526B',
    'mutedDark':  '#9BA6BC',
    'line':       '#E3E0D6',
    'approve':    '#1F8A5B',
    'pending':    '#D98A00',
    'reject':     '#D93A3A',
    'white':      '#FFFFFF',
}

# ───────────────────────── Fuentes ─────────────────────────
FONT_FILES = {
    ('poppins', 800): 'node_modules/@fontsource/poppins/files/poppins-latin-800-normal.woff',
    ('plex', 500):    'node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-500-normal.woff',
}
_fonts = {}
def _font(fam, w):
    k = (fam, w)
    if k not in _fonts:
        _fonts[k] = TTFont(os.path.join(HERE, FONT_FILES[k]))
    return _fonts[k]

def glyphs(text, fam='poppins', w=800, size=1000, tracking=0, adjust=None):
    """Lista de (char, d, x, adv) en unidades de 'size' (y hacia abajo, línea base en y=0).
    tracking en milésimas de em. adjust: {indice: unidades extra antes del glifo}."""
    f = _font(fam, w); gs = f.getGlyphSet(); cmap = f.getBestCmap(); upm = f['head'].unitsPerEm
    k = size / upm; x = 0.0; out = []
    for i, ch in enumerate(text):
        if adjust and i in adjust: x += adjust[i]
        g = gs[cmap[ord(ch)]]
        if ch != ' ':
            pen = SVGPathPen(gs)
            g.draw(TransformPen(pen, (k, 0, 0, -k, x, 0)))
            out.append((ch, pen.getCommands(), x, g.width * k))
        else:
            out.append((ch, '', x, g.width * k))
        x += g.width * k + tracking * size / 1000
    width = x - tracking * size / 1000
    return out, width

def ink_bounds(d_list):
    """Caja de tinta exacta de una lista de paths SVG (usa svgpathtools-free parsing vía fontTools)."""
    from fontTools.svgLib.path import parse_path
    from fontTools.pens.boundsPen import BoundsPen
    bp = BoundsPen(None)
    for d in d_list:
        if d: parse_path(d, bp)
    return bp.bounds

# ───────────────────────── Símbolo: la tecla ─────────────────────────
# Caja 1000×1000. Cara (face) + labio (lip) = una tecla vista de frente.
KX, KW, KH, KR, KD = 80, 840, 760, 200, 100
KY = (1000 - (KH + KD)) / 2          # 70
PRESS = 72                           # recorrido de la tecla al presionar
LEG_SCALE = 0.52                     # tamaño de la "m" sobre la cara

M_GLYPHS, _ = glyphs('m')
M_D = M_GLYPHS[0][1]
M_X0, M_Y0, M_X1, M_Y1 = 56, -566, 1016, 0   # caja de tinta de la m (Poppins 800)

def legend_transform():
    s = LEG_SCALE
    cx = KX + KW / 2; cy = KY + KH / 2
    tx = cx - (M_X0 + M_X1) / 2 * s
    ty = cy + 561 * s / 2 + 4          # centrado óptico en la altura x
    return f'translate({tx:.2f} {ty:.2f}) scale({s})'

THEMES = {
    # nombre: (cara, leyenda, labio)
    'light': (C['ink'],   C['paper'], C['signal']),   # para fondos claros (principal)
    'dark':  (C['paper'], C['ink'],   C['signal']),   # para fondos oscuros
    'signal': (C['signal'], C['white'], C['ink']),    # variante de alto impacto
}

def symbol_body(theme='light', uid='mk', mono=None):
    """Contenido del símbolo en caja 1000. Clases para animar: mk-key, mk-ring, mk-lip, mk-face."""
    face_rect = f'x="{KX}" y="{KY}" width="{KW}" height="{KH}" rx="{KR}"'
    lip_rect = f'x="{KX}" y="{KY + KD}" width="{KW}" height="{KH}" rx="{KR}"'
    ring = (f'<rect class="mk-ring" x="{KX}" y="{KY}" width="{KW}" height="{KH + KD}" rx="{KR}" '
            f'fill="none" stroke="{C["signal"]}" stroke-width="18" opacity="0"/>')
    if mono:
        gap = 30
        defs = (f'<defs><mask id="{uid}-lipm" maskUnits="userSpaceOnUse" x="0" y="0" width="1000" height="1000">'
                f'<rect width="1000" height="1000" fill="#fff"/>'
                f'<rect x="{KX - gap}" y="{KY - gap}" width="{KW + 2 * gap}" height="{KH + 2 * gap}" rx="{KR + gap}" fill="#000"/></mask>'
                f'<mask id="{uid}-facem" maskUnits="userSpaceOnUse" x="0" y="0" width="1000" height="1000">'
                f'<rect {face_rect} fill="#fff"/><path transform="{legend_transform()}" d="{M_D}" fill="#000"/></mask></defs>')
        return (defs + f'<g class="mk-key"><rect class="mk-lip" {lip_rect} fill="{mono}" mask="url(#{uid}-lipm)"/>'
                f'<g class="mk-face"><rect {face_rect} fill="{mono}" mask="url(#{uid}-facem)"/></g></g>')
    face, legend, lip = THEMES[theme]
    return (f'<g class="mk-key">{ring}<rect class="mk-lip" {lip_rect} fill="{lip}"/>'
            f'<g class="mk-face"><rect {face_rect} fill="{face}"/>'
            f'<path transform="{legend_transform()}" d="{M_D}" fill="{legend}"/></g></g>')

# ───────────────────────── Wordmark ─────────────────────────
WM_TRACK = -28                    # milésimas de em
WM_ADJ = {1: -6, 2: 4, 4: -4}     # ajustes ópticos (c más cerca de m, p respira, e/r)
WM, WM_W = glyphs('mcpress', tracking=WM_TRACK, adjust=WM_ADJ)
_wb = ink_bounds([g[1] for g in WM])
WM_INK = _wb                      # (x0, y0, x1, y1)  y0≈-566 (altura x), y1≈+240 (descendente p)

def wordmark_body(color, cls_prefix='wm'):
    parts = []
    for i, (ch, d, x, adv) in enumerate(WM):
        parts.append(f'<path class="{cls_prefix}-l {cls_prefix}-l{i}" d="{d}" fill="{color}"/>')
    return ''.join(parts)

# Descriptor "by TBA Digitals"
DESC_TEXT = 'BY TBA DIGITALS'
DESC_SIZE = 1000
DESC, DESC_W = glyphs(DESC_TEXT, fam='plex', w=500, tracking=160)
DESC_CAP = 698 * DESC_SIZE / 1000   # altura de mayúsculas Plex Mono ≈ 0.698 em

def descriptor_body(text_color, sq_color):
    sq = DESC_CAP
    gapx = 0.62 * DESC_SIZE * 0.62
    parts = [f'<rect class="ds-sq" x="0" y="{-sq:.1f}" width="{sq:.1f}" height="{sq:.1f}" fill="{sq_color}"/>',
             f'<g transform="translate({sq + gapx:.1f} 0)"><g class="ds-tx">']
    for ch, d, x, adv in DESC:
        if d: parts.append(f'<path d="{d}" fill="{text_color}"/>')
    parts.append('</g></g>')
    total_w = sq + gapx + DESC_W
    return ''.join(parts), total_w

# ───────────────────────── Composición de lockups ─────────────────────────
WM_SIZE = 0.74          # tamaño de fuente del wordmark relativo a la caja del símbolo (1000 → 800)
GAP_SYM_WM = 190        # espacio entre la cara de la tecla y la m del wordmark
DESC_SCALE = 0.128      # tamaño del descriptor relativo a la caja (1000 → 118)

def lockup(kind='horizontal', theme='light', endorsed=False, uid='lk', mono=None, pad=40):
    """Devuelve (svg_inner, viewBox) en unidades de la caja del símbolo."""
    wm_color = C['ink'] if theme == 'light' else C['paper']
    desc_color = C['muted'] if theme == 'light' else C['mutedDark']
    sq_color = C['signal']
    if mono: wm_color = desc_color = sq_color = mono
    def sym(): return symbol_body(theme, uid, mono=mono)
    s = WM_SIZE
    x0, y0, x1, y1 = WM_INK
    if kind == 'horizontal':
        # línea base del wordmark: centro de la altura x alineado al centro de la cara
        face_cy = KY + KH / 2 + 8
        base = face_cy + 561 * s / 2
        wm_x = KX + KW + GAP_SYM_WM - x0 * s
        body = [f'<g class="lk-sym">{sym()}</g>',
                f'<g class="lk-wm" transform="translate({wm_x:.1f} {base:.1f}) scale({s})">{wordmark_body(wm_color)}</g>']
        right = wm_x + x1 * s
        bottom = base + y1 * s
        if endorsed:
            dbody, dw = descriptor_body(desc_color, sq_color)
            ds = DESC_SCALE
            dx = right - dw * ds
            dy = base + y1 * s   # línea base del descriptor = fondo de la p
            body.append(f'<g class="lk-ds" transform="translate({dx:.1f} {dy:.1f}) scale({ds})">{dbody}</g>')
            bottom = max(bottom, dy)
        vb = (KX - pad, KY - pad, right - KX + 2 * pad, max(bottom, KY + KH + KD) - KY + 2 * pad)
        return ''.join(body), vb
    if kind == 'stacked':
        s = 0.62
        wm_w = (x1 - x0) * s
        cx = KX + KW / 2
        base = KY + KH + KD + 130 + 566 * s
        wm_x = cx - wm_w / 2 - x0 * s
        body = [f'<g class="lk-sym">{sym()}</g>',
                f'<g class="lk-wm" transform="translate({wm_x:.1f} {base:.1f}) scale({s})">{wordmark_body(wm_color)}</g>']
        bottom = base + y1 * s
        if endorsed:
            dbody, dw = descriptor_body(desc_color, sq_color)
            ds = DESC_SCALE
            dx = cx - dw * ds / 2
            dy = bottom + 110 + DESC_CAP * ds
            body.append(f'<g class="lk-ds" transform="translate({dx:.1f} {dy:.1f}) scale({ds})">{dbody}</g>')
            bottom = dy
        left = min(KX, wm_x + x0 * s); right = max(KX + KW, wm_x + x1 * s)
        vb = (left - pad, KY - pad, right - left + 2 * pad, bottom - KY + 2 * pad)
        return ''.join(body), vb
    raise ValueError(kind)

def wordmark_only(theme='light'):
    color = C['ink'] if theme == 'light' else C['paper']
    x0, y0, x1, y1 = WM_INK
    pad = 30
    vb = (x0 - pad, y0 - pad, x1 - x0 + 2 * pad, y1 - y0 + 2 * pad)
    return f'<g class="lk-wm">{wordmark_body(color)}</g>', vb

# ───────────────────────── Animación (CSS dentro del SVG) ─────────────────────────
EASE_OUT = 'cubic-bezier(.16,1,.3,1)'
EASE_IN = 'cubic-bezier(.55,0,.85,.25)'
SPRING = 'cubic-bezier(.3,1.65,.5,1)'
EASE_IO = 'cubic-bezier(.45,0,.2,1)'

def keyframes(name, total, frames):
    """frames: [(t_seg, 'props', timing_fn_hacia_el_siguiente)] → @keyframes"""
    frames = sorted(frames, key=lambda f: f[0])
    if frames[0][0] > 0: frames.insert(0, (0, frames[0][1], 'linear'))
    if frames[-1][0] < total: frames.append((total, frames[-1][1], 'linear'))
    out = [f'@keyframes {name}{{']
    for t, props, fn in frames:
        pct = round(100 * t / total, 3)
        tf = f'animation-timing-function:{fn};' if fn else ''
        out.append(f'{pct}%{{{props};{tf}}}')
    out.append('}')
    return ''.join(out)

def press_frames(t_down, depth=PRESS):
    """Presión de la tecla: baja rápido, sostiene, rebota con resorte."""
    return [(0, 'transform:translateY(0px)', 'linear'),
            (t_down, 'transform:translateY(0px)', EASE_IN),
            (t_down + 0.12, f'transform:translateY({depth}px)', 'linear'),
            (t_down + 0.22, f'transform:translateY({depth}px)', SPRING),
            (t_down + 0.56, 'transform:translateY(0px)', 'linear')]

def ring_frames(t_hit):
    return [(0, 'opacity:0;transform:scale(1)', 'linear'),
            (t_hit, 'opacity:0;transform:scale(1)', 'linear'),
            (t_hit + 0.01, 'opacity:.9;transform:scale(1)', EASE_OUT),
            (t_hit + 0.75, 'opacity:0;transform:scale(1.14)', 'linear')]

def lip_frames(t_hit):
    return [(0, f'fill:{C["signal"]}', 'linear'),
            (t_hit, f'fill:{C["signal"]}', 'linear'),
            (t_hit + 0.02, f'fill:{C["glow"]}', EASE_OUT),
            (t_hit + 0.45, f'fill:{C["signal"]}', 'linear')]

BASE_CSS = ('.mk-ring,.wm-l,.ds-sq,.ds-tx,.mk-key,.mk-face{transform-box:fill-box;transform-origin:50% 50%}'
            '.mk-ring{opacity:0}')
REDUCED = '@media (prefers-reduced-motion:reduce){*{animation:none!important}}'

def css_intro(with_wm=True, with_desc=False, lip_theme_color=None):
    """Intro de una sola vez. Estado final = logo estático (y es lo que ve reduced-motion)."""
    T = 2.0 if with_wm else 1.5
    css = [BASE_CSS]
    css.append(keyframes('mkDrop', T, [(0, 'opacity:0;transform:translateY(-130px)', EASE_OUT),
                                       (0.46, 'opacity:1;transform:translateY(0px)', 'linear')]))
    css.append(keyframes('mkPress', T, press_frames(0.50)))
    css.append(keyframes('mkRing', T, ring_frames(0.62)))
    if lip_theme_color is None:
        css.append(keyframes('mkLip', T, lip_frames(0.62)))
        css.append(f'.mk-lip{{animation:mkLip {T}s linear both}}')
    css.append(f'.mk-key{{animation:mkDrop {T}s linear both}}')
    css.append(f'.mk-face{{animation:mkPress {T}s linear both}}')
    css.append(f'.mk-ring{{animation:mkRing {T}s linear both}}')
    if with_wm:
        for i in range(len(WM)):
            s0 = 0.66 + 0.055 * i
            css.append(keyframes(f'wmIn{i}', T, [(0, 'opacity:0;transform:translateX(-170px)', 'linear'),
                                                  (s0, 'opacity:0;transform:translateX(-170px)', EASE_OUT),
                                                  (s0 + 0.42, 'opacity:1;transform:translateX(0px)', 'linear')]))
            css.append(f'.wm-l{i}{{animation:wmIn{i} {T}s linear both}}')
    if with_desc:
        css.append(keyframes('dsSq', T, [(0, 'transform:scale(0)', 'linear'), (1.30, 'transform:scale(0)', SPRING),
                                         (1.62, 'transform:scale(1)', 'linear')]))
        css.append(keyframes('dsTx', T, [(0, 'opacity:0;transform:translateX(-260px)', 'linear'),
                                         (1.40, 'opacity:0;transform:translateX(-260px)', EASE_OUT),
                                         (1.86, 'opacity:1;transform:translateX(0px)', 'linear')]))
        css.append(f'.ds-sq{{animation:dsSq {T}s linear both}}.ds-tx{{animation:dsTx {T}s linear both}}')
    css.append(REDUCED)
    return ''.join(css), T

def css_loop(with_wm=True, with_desc=False, cycle=None):
    """Bucle continuo sin cortes: la tecla se presiona y la señal recorre la palabra."""
    L = cycle or (3.6 if with_wm else 1.8)
    t_down = 0.25; t_hit = t_down + 0.12
    css = [BASE_CSS]
    css.append(keyframes('mkPressL', L, press_frames(t_down)))
    css.append(keyframes('mkRingL', L, ring_frames(t_hit)))
    css.append(keyframes('mkLipL', L, lip_frames(t_hit)))
    css.append(f'.mk-face{{animation:mkPressL {L}s linear infinite}}.mk-ring{{animation:mkRingL {L}s linear infinite}}'
               f'.mk-lip{{animation:mkLipL {L}s linear infinite}}')
    if with_wm:
        for i in range(len(WM)):
            s0 = t_hit + 0.06 + 0.05 * i
            css.append(keyframes(f'wmWave{i}', L, [(0, 'transform:translateY(0px)', 'linear'),
                                                    (s0, 'transform:translateY(0px)', EASE_OUT),
                                                    (s0 + 0.16, 'transform:translateY(-70px)', EASE_IO),
                                                    (s0 + 0.46, 'transform:translateY(0px)', 'linear')]))
            css.append(f'.wm-l{i}{{animation:wmWave{i} {L}s linear infinite}}')
    if with_desc:
        s_end = t_hit + 0.06 + 0.05 * len(WM) + 0.12
        css.append(keyframes('dsPing', L, [(0, 'transform:scale(1)', 'linear'), (s_end, 'transform:scale(1)', EASE_OUT),
                                           (s_end + 0.12, 'transform:scale(1.45)', EASE_IO),
                                           (s_end + 0.42, 'transform:scale(1)', 'linear')]))
        css.append(f'.ds-sq{{animation:dsPing {L}s linear infinite}}')
    css.append(REDUCED)
    return ''.join(css), L

# ───────────────────────── Escritura ─────────────────────────
def svg_doc(inner, vb, title, css=None, bg=None, extra_attrs=''):
    x, y, w, h = vb
    bgrect = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="{bg}"/>' if bg else ''
    style = f'<style>{css}</style>' if css else ''
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{x:.1f} {y:.1f} {w:.1f} {h:.1f}" '
            f'role="img" aria-label="{title}"{extra_attrs}><title>{title}</title>{style}{bgrect}{inner}</svg>\n')

def write(rel, content):
    p = os.path.join(OUT, rel); os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, 'w').write(content)
    return p

SYM_VB = (KX - 40, KY - 40, KW + 80, KH + KD + 80)   # caja ajustada al símbolo (con margen)
SYM_VB_SQ = (0, 0, 1000, 1000)                        # caja cuadrada (iconos)

def build():
    files = []
    # Símbolo estático
    for th in ('light', 'dark', 'signal'):
        files.append(write(f'logo/svg/mcpress-symbol-{th}.svg', svg_doc(symbol_body(th, f's{th}'), SYM_VB_SQ, 'MCPress')))
    for name, col in (('ink', C['ink']), ('paper', C['paper']), ('black', '#000000'), ('white', '#FFFFFF')):
        files.append(write(f'logo/svg/mcpress-symbol-mono-{name}.svg', svg_doc(symbol_body(uid=f'm{name}', mono=col), SYM_VB_SQ, 'MCPress')))
    # Wordmark
    for th in ('light', 'dark'):
        inner, vb = wordmark_only(th)
        files.append(write(f'logo/svg/mcpress-wordmark-{th}.svg', svg_doc(inner, vb, 'mcpress')))
    # Lockups
    for kind in ('horizontal', 'stacked'):
        for th in ('light', 'dark'):
            for end in (False, True):
                inner, vb = lockup(kind, th, end, uid=f'{kind[0]}{th}{int(end)}')
                suffix = '-endorsed' if end else ''
                files.append(write(f'logo/svg/mcpress-logo-{kind}{suffix}-{th}.svg', svg_doc(inner, vb, 'MCPress by TBA Digitals' if end else 'MCPress')))
    for name, col in (('ink', C['ink']), ('white', '#FFFFFF')):
        inner, vb = lockup('horizontal', 'light', True, uid=f'hm{name}', mono=col)
        files.append(write(f'logo/svg/mcpress-logo-horizontal-endorsed-mono-{name}.svg', svg_doc(inner, vb, 'MCPress by TBA Digitals')))
    # Animados
    for th in ('light', 'dark'):
        inner, vb = lockup('horizontal', th, True, uid=f'ai{th}', pad=90)
        css, _ = css_intro(True, True)
        files.append(write(f'logo/animated/mcpress-logo-intro-{th}.svg', svg_doc(inner, vb, 'MCPress by TBA Digitals', css)))
        css, _ = css_loop(True, True)
        files.append(write(f'logo/animated/mcpress-logo-loop-{th}.svg', svg_doc(inner, vb, 'MCPress by TBA Digitals', css)))
        inner, vb = lockup('horizontal', th, False, uid=f'an{th}', pad=90)
        css, _ = css_intro(True, False)
        files.append(write(f'logo/animated/mcpress-logo-intro-plain-{th}.svg', svg_doc(inner, vb, 'MCPress', css)))
        css, _ = css_intro(False)
        files.append(write(f'logo/animated/mcpress-symbol-intro-{th}.svg', svg_doc(symbol_body(th, f'si{th}'), SYM_VB_SQ, 'MCPress', css)))
        css, _ = css_loop(False)
        files.append(write(f'logo/animated/mcpress-symbol-loader-{th}.svg', svg_doc(symbol_body(th, f'sl{th}'), SYM_VB_SQ, 'Cargando MCPress', css)))
    return files

if __name__ == '__main__':
    fs = build()
    print(len(fs), 'archivos en', OUT)
    print('WM ink', WM_INK, 'width', WM_W, 'desc width', DESC_W)

# ───────────────────────── Snippet para la web ─────────────────────────
def embed_html(theme='light', endorsed=False):
    import re
    inner, vb = lockup('horizontal', theme, endorsed, uid=f'emb{theme}', pad=90)
    intro, T = css_intro(True, endorsed)
    hover, L = css_loop(True, endorsed, cycle=1.4)
    def scope(css, pre, state=''):
        css = css.replace(BASE_CSS, '').replace(REDUCED, '')
        css = re.sub(r'@keyframes (\w+)', lambda m: f'@keyframes {pre}{m.group(1)}', css)
        css = re.sub(r'animation:(\w+) ', lambda m: f'animation:{pre}{m.group(1)} ', css)
        css = re.sub(r'(?<![\w-])\.(mk-|wm-l|ds-)', lambda m: f'.mcpress-logo{state} .{m.group(1)}', css)
        return css
    css_intro_s = scope(intro, 'mcpress-')
    css_hover_s = scope(hover, 'mcpress-hp-', '.is-pressing').replace(' infinite', ' 1 both')
    base = ('.mcpress-logo{display:inline-block;line-height:0}.mcpress-logo svg{height:100%;width:auto;display:block;overflow:visible}'
            '.mcpress-logo .mk-ring,.mcpress-logo .wm-l,.mcpress-logo .ds-sq,.mcpress-logo .ds-tx,.mcpress-logo .mk-key,.mcpress-logo .mk-face'
            '{transform-box:fill-box;transform-origin:50% 50%}.mcpress-logo .mk-ring{opacity:0}')
    ready = '.mcpress-logo.is-ready *{animation:none}'
    reduced = '@media (prefers-reduced-motion:reduce){.mcpress-logo *{animation:none!important}}'
    x, y, w, h = vb
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{x:.1f} {y:.1f} {w:.1f} {h:.1f}" aria-hidden="true">{inner}</svg>')
    js = ('(function(){document.querySelectorAll(".mcpress-logo").forEach(function(el){'
          'setTimeout(function(){el.classList.add("is-ready")},' + str(int(T * 1000) + 50) + ');'
          'var busy=false;function press(){if(busy||!el.classList.contains("is-ready"))return;busy=true;el.classList.add("is-pressing");'
          'setTimeout(function(){el.classList.remove("is-pressing");busy=false},' + str(int(L * 1000)) + ')}'
          'el.addEventListener("mouseenter",press);el.addEventListener("focus",press);});})();')
    return ('<!-- MCPress · logo animado para la web (header o hero).\n'
            '     Intro de 2 s al cargar; al pasar el cursor la tecla se vuelve a presionar.\n'
            '     Respeta prefers-reduced-motion (muestra el logo estático). Ajusta la altura con .mcpress-logo{height:…} -->\n'
            f'<a class="mcpress-logo" href="/" aria-label="MCPress by TBA Digitals — inicio" style="height:{96 if endorsed else 44}px">\n{svg}\n</a>\n'
            f'<style>{base}{css_intro_s}{ready}{css_hover_s}{reduced}</style>\n<script>{js}</script>\n')

def favicon_svg():
    body = symbol_body('light', 'fav')
    dark = (f'@media (prefers-color-scheme:dark){{.mk-face rect{{fill:{C["paper"]}}}.mk-face path{{fill:{C["ink"]}}}}}')
    return svg_doc(body, (60, 50, 880, 900), 'MCPress', css=dark)

def tokens_css():
    lines = [':root{', '  /* MCPress · tokens de marca (sub-marca de TBA Digitals) */']
    names = {'ink': 'tinta', 'paper': 'papel', 'signal': 'senal', 'glow': 'senal-clara', 'signalDeep': 'senal-presionada',
             'muted': 'texto-suave', 'mutedDark': 'texto-suave-oscuro', 'line': 'linea', 'approve': 'aprobado',
             'pending': 'pendiente', 'reject': 'rechazado', 'white': 'blanco'}
    for k, v in C.items():
        lines.append(f'  --mcp-{names[k]}: {v};')
    lines += ["  --mcp-font-display: 'Poppins', system-ui, -apple-system, sans-serif;  /* 800 titulares */",
              "  --mcp-font-body: 'Poppins', system-ui, -apple-system, sans-serif;     /* 400/500 texto */",
              "  --mcp-font-mono: 'IBM Plex Mono', ui-monospace, 'SF Mono', monospace; /* 500 etiquetas, código */",
              '  --mcp-radius-key: 24%;          /* radio de la tecla = 24 % del lado */',
              '  --mcp-key-depth: 11.6%;         /* profundidad del labio = 100/860 de la altura */',
              '  --mcp-ease-press: cubic-bezier(.55,0,.85,.25);',
              '  --mcp-ease-release: cubic-bezier(.3,1.65,.5,1);',
              '  --mcp-ease-out: cubic-bezier(.16,1,.3,1);',
              '}',
              '/* Botón "tecla" de MCPress: el mismo gesto del logo aplicado a la UI */',
              '.mcp-key-btn{font:600 1rem/1 var(--mcp-font-body);color:#fff;background:var(--mcp-senal);border:0;border-radius:14px;',
              '  padding:.95em 1.5em;box-shadow:0 6px 0 var(--mcp-tinta);transform:translateY(0);transition:transform .12s var(--mcp-ease-press),box-shadow .12s var(--mcp-ease-press);cursor:pointer}',
              '.mcp-key-btn:hover{background:var(--mcp-senal-presionada)}',
              '.mcp-key-btn:active{transform:translateY(5px);box-shadow:0 1px 0 var(--mcp-tinta)}',
              '.mcp-key-btn:focus-visible{outline:3px solid var(--mcp-senal-clara);outline-offset:3px}',
              '@media (prefers-reduced-motion:reduce){.mcp-key-btn{transition:none}}']
    return '\n'.join(lines) + '\n'

def build_extras():
    for th in ('light', 'dark'):
        write(f'logo/animated/embed/mcpress-logo-header-{th}.html', embed_html(th, False))
        write(f'logo/animated/embed/mcpress-logo-hero-{th}.html', embed_html(th, True))
    write('web/favicon.svg', favicon_svg())
    write('wordpress-org/icon.svg', svg_doc(f'<rect width="1000" height="1000" fill="{C["paper"]}"/>'
                                           f'<g transform="translate(80 70) scale(.84)">{symbol_body("light", "wpo")}</g>', (0, 0, 1000, 1000), 'MCPress'))
    write('tokens.css', tokens_css())

if __name__ == '__main__':
    build_extras(); print('extras ok')
