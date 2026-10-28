"""Composiciones raster (iconos, banners, OG, avatar). Genera HTML en compose/ que render.js captura."""
import os
import build_brand as B

HERE = os.path.dirname(os.path.abspath(__file__))
CD = os.path.join(HERE, 'compose'); os.makedirs(CD, exist_ok=True)
C = B.C
FONTS = f'''
@font-face{{font-family:Poppins;font-weight:800;src:url(file://{HERE}/node_modules/@fontsource/poppins/files/poppins-latin-800-normal.woff2)}}
@font-face{{font-family:Poppins;font-weight:700;src:url(file://{HERE}/node_modules/@fontsource/poppins/files/poppins-latin-700-normal.woff2)}}
@font-face{{font-family:Poppins;font-weight:500;src:url(file://{HERE}/node_modules/@fontsource/poppins/files/poppins-latin-500-normal.woff2)}}
@font-face{{font-family:Poppins;font-weight:400;src:url(file://{HERE}/node_modules/@fontsource/poppins/files/poppins-latin-400-normal.woff2)}}
@font-face{{font-family:Plex;font-weight:500;src:url(file://{HERE}/node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-500-normal.woff2)}}
@font-face{{font-family:Plex;font-weight:400;src:url(file://{HERE}/node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-400-normal.woff2)}}
'''
_uid = [0]
def uid():
    _uid[0] += 1; return f'u{_uid[0]}'

def key_svg(theme='light', px=200, pressed=False, rings=0, ring_color=None, extra_style=''):
    """Símbolo como <svg> inline de px de ancho. rings: anillos de 'señal' alrededor (estado presionado)."""
    ring_color = ring_color or C['signal']
    grow = 70 * rings
    vb = (B.KX - 30 - grow, B.KY - 30 - grow, B.KW + 60 + 2 * grow, B.KH + B.KD + 60 + 2 * grow)
    rs = []
    for i in range(rings, 0, -1):
        g = 70 * i - 20
        op = 0.55 / i
        rs.append(f'<rect x="{B.KX - g}" y="{B.KY - g + (B.PRESS if pressed else 0) * 0}" width="{B.KW + 2 * g}" '
                  f'height="{B.KH + B.KD + 2 * g}" rx="{B.KR + g}" fill="none" stroke="{ring_color}" stroke-width="{14}" opacity="{op:.2f}"/>')
    face_style = f'<style>.{(i:=uid())} .mk-face{{transform:translateY({B.PRESS}px)}}</style>' if pressed else ''
    h = px * vb[3] / vb[2]
    return (f'<svg class="{i if pressed else ""}" xmlns="http://www.w3.org/2000/svg" viewBox="{vb[0]} {vb[1]} {vb[2]} {vb[3]}" '
            f'width="{px:.0f}" height="{h:.0f}" style="{extra_style}">{face_style}{"".join(rs)}{B.symbol_body(theme, uid())}</svg>')

def raw(svg_inner_vb, px, style=''):
    inner, vb = svg_inner_vb
    h = px * vb[3] / vb[2]
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb[0]} {vb[1]} {vb[2]} {vb[3]}" width="{px:.0f}" '
            f'height="{h:.0f}" style="{style}">{inner}</svg>')

def page(name, w, h, body, bg, css=''):
    html = (f'<!doctype html><html><head><meta charset="utf-8"><style>{FONTS}*{{box-sizing:border-box;margin:0;padding:0}}'
            f'html,body{{width:{w}px;height:{h}px;overflow:hidden;background:{bg}}}{css}</style></head>'
            f'<body>{body}</body></html>')
    open(os.path.join(CD, name + '.html'), 'w').write(html)
    return name, w, h

def centered_symbol(theme, size, frac, bg):
    k = key_svg(theme, size * frac)
    return f'<div style="width:{size}px;height:{size}px;display:grid;place-items:center;background:{bg}">{k}</div>'

def keyboard_grid(w, h, key=150, gap=24, x0=0, y0=0, legends=None, face='#132239', lip='#070F1C', leg='#2C3D5C', skip=None):
    """Teclado decorativo: filas desplazadas como un teclado real."""
    legends = legends or {}
    out = []
    lipd = key * 0.1
    row = 0; y = y0
    while y < h + key:
        off = (row % 3) * key * 0.33
        x = x0 - off; col = 0
        while x < w + key:
            if not (skip and (row, col) in skip):
                out.append(f'<div style="position:absolute;left:{x:.0f}px;top:{y + lipd:.0f}px;width:{key}px;height:{key - lipd:.0f}px;border-radius:{key * .24:.0f}px;background:{lip}"></div>'
                           f'<div style="position:absolute;left:{x:.0f}px;top:{y:.0f}px;width:{key}px;height:{key - lipd:.0f}px;border-radius:{key * .24:.0f}px;background:{face};'
                           f'display:grid;place-items:center;font:500 {key * .2:.0f}px Plex;color:{leg}">{legends.get((row, col), "")}</div>')
            x += key + gap; col += 1
        y += key + gap * 0.6; row += 1
    return ''.join(out)

def sq(px, color=None):
    return f'<span style="display:inline-block;width:{px}px;height:{px}px;background:{color or C["signal"]};margin-right:.6em;vertical-align:baseline"></span>'

def banner(w, h, lang='en'):
    s = w / 1544
    key = 168 * s; gap = 26 * s
    legends = {(0, 3): 'wp', (0, 6): 'tab', (1, 2): '/', (1, 7): 'esc', (2, 4): 'mcp', (2, 8): '{ }',
               (0, 9): 'ctrl', (1, 4): 'cli', (2, 6): '>_', (3, 3): 'alt', (3, 7): '?', (1, 9): '#'}
    grid = keyboard_grid(w * 1.1, h, key=round(key), gap=gap, x0=w * 0.40, y0=h / 2 - (key + gap * 0.6) - key / 2, legends=legends,
                         skip={(1, 3)})
    # tecla MCPress en el hueco (fila 1, col 3)
    off = (1 % 3) * key * 0.33
    kx = w * 0.40 - off + 3 * (key + gap); ky = h / 2 - key / 2
    ksz = key / (B.KW / (B.KW + 60 + 2 * 70 * 3))
    hero = key_svg('dark', ksz, pressed=True, rings=3)
    hero_w = ksz; hero_h = ksz * ((B.KH + B.KD + 60 + 420) / (B.KW + 60 + 420))
    cx = kx + key / 2; cy = ky + key / 2 + 4 * s
    em = f'<span style="color:{C["glow"]}">'
    head = (f'Your AI runs WordPress.<br>You set the {em}rules</span>.' if lang == 'en' else f'Tu IA maneja WordPress.<br>Tú pones las {em}reglas</span>.')
    eyebrow = 'MCP FOR WORDPRESS' if lang == 'en' else 'MCP PARA WORDPRESS'
    body = f'''
<div style="position:absolute;inset:0;background:{C['ink']}"></div>
<div style="position:absolute;inset:0">{grid}</div>
<div style="position:absolute;left:{cx - hero_w / 2:.0f}px;top:{cy - hero_h / 2:.0f}px">{hero}</div>
<div style="position:absolute;inset:0;background:linear-gradient(90deg,{C['ink']} 0%,{C['ink']} 34%,rgba(13,26,46,.0) 62%)"></div>
<div style="position:absolute;left:{70 * s:.0f}px;top:{74 * s:.0f}px;color:{C['paper']}">
  <div style="font:500 {19 * s:.1f}px Plex;letter-spacing:.18em;color:{C['glow']};margin-bottom:{22 * s:.0f}px">{sq(round(14 * s))}{eyebrow}</div>
  <div style="font:800 {62 * s:.1f}px/1.06 Poppins;letter-spacing:-.025em">{head}</div>
</div>'''
    return body

def og(w=1200, h=630):
    lk = raw(B.lockup('horizontal', 'light', True, uid='og'), 430)
    hero = key_svg('light', 470, pressed=True, rings=3)
    return f'''
<div style="position:absolute;inset:0;background:{C['paper']}"></div>
<div style="position:absolute;right:-70px;top:50%;transform:translateY(-50%)">{hero}</div>
<div style="position:absolute;left:72px;top:64px">{lk}</div>
<div style="position:absolute;left:80px;top:212px;width:660px;color:{C['ink']}">
  <div style="font:800 64px/1.04 Poppins;letter-spacing:-.03em">Tu IA maneja WordPress.<br>Tú pones las <span style="color:{C['signal']}">reglas</span>.</div>
  <div style="font:400 25px/1.45 Poppins;color:{C['muted']};margin-top:26px;max-width:560px">Conecta Claude, ChatGPT o Cursor por MCP. Con tu aprobación o en automático.</div>
</div>
<div style="position:absolute;left:80px;bottom:56px;font:500 21px Plex;letter-spacing:.08em;color:{C['ink']}">{sq(14)}mcpress.tbadigitals.com</div>'''

def build():
    jobs = []
    # WordPress.org
    for n in (128, 256):
        jobs.append(page(f'wporg-icon-{n}', n, n, centered_symbol('light', n, 0.84, C['paper']), C['paper']) + (f'wordpress-org/icon-{n}x{n}.png',))
    jobs.append(page('wporg-banner-1544', 1544, 500, banner(1544, 500), C['ink']) + ('wordpress-org/banner-1544x500.png',))
    jobs.append(page('wporg-banner-772', 772, 250, banner(772, 250), C['ink']) + ('wordpress-org/banner-772x250.png',))
    jobs.append(page('es-banner-1544', 1544, 500, banner(1544, 500, 'es'), C['ink']) + ('social/banner-es-1544x500.png',))
    # Web
    for n, f in ((180, 'web/apple-touch-icon.png'), (192, 'web/icon-192.png'), (512, 'web/icon-512.png')):
        jobs.append(page(f'web-{n}', n, n, centered_symbol('light', n, 0.74, C['paper']), C['paper']) + (f,))
    for n in (16, 32, 48, 64):
        jobs.append(page(f'fav-{n}', n, n, f'<div style="width:{n}px;height:{n}px;display:grid;place-items:center">{key_svg("light", n * 1.06)}</div>', 'transparent') + (f'web/favicon-{n}.png',))
    jobs.append(page('og', 1200, 630, og(), C['paper']) + ('web/og-image.png',))
    # Social
    jobs.append(page('avatar', 800, 800, centered_symbol('dark', 800, 0.58, C['ink']), C['ink']) + ('social/avatar-800.png',))
    jobs.append(page('avatar-light', 800, 800, centered_symbol('light', 800, 0.58, C['paper']), C['paper']) + ('social/avatar-800-light.png',))
    # PNG de logos
    for th, bg in (('light', 'transparent'), ('dark', 'transparent')):
        for kind, end, w in (('horizontal', True, 2400), ('horizontal', False, 2400), ('stacked', True, 1400)):
            iv = B.lockup(kind, th, end, uid=uid())
            vb = iv[1]; hh = round(w * vb[3] / vb[2])
            nm = f'mcpress-logo-{kind}{"-endorsed" if end else ""}-{th}'
            jobs.append(page(nm, w, hh, raw(iv, w), bg) + (f'logo/png/{nm}.png',))
        jobs.append(page(f'sym-{th}', 1024, 1024, f'<div style="display:grid;place-items:center;width:1024px;height:1024px">{key_svg(th, 1024 * 0.94)}</div>', 'transparent') + (f'logo/png/mcpress-symbol-{th}-1024.png',))
    with open(os.path.join(CD, 'jobs.tsv'), 'w') as f:
        for name, w, h, out in jobs:
            f.write(f'{name}\t{w}\t{h}\t{out}\n')
    return jobs

if __name__ == '__main__':
    print(len(build()), 'composiciones')
