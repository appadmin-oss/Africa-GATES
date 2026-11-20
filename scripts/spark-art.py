#!/usr/bin/env python3
"""
The "spark" illustrations: the pictures beside a money target and in the announcement card.

Two styles of every subject, drawn as vectors so they are sharp at any size and a few KB each
(this host has no image model and no headless renderer, so nothing is generated at runtime):

  bold   glossy 3D objects on a soft gradient blob — the playful fintech-app style
         (owner, 20 Nov 2026: "OPay-styled"), in Africa GATES' own greens and golds
  house  the house illustration style of public/assets/img/illustrations/: flat shapes on a
         cream disc, gold and green, a scatter of confetti

Run:  python3 scripts/spark-art.py   → writes public/assets/img/spark/{subject}-{style}.svg
The files are committed; this script is how they are changed, never by hand.

Colours: the palette is Support\\Accent's (green #237b22 family, gold #f3b416 family, live
#e0245e, info #1f6fa3, ink #10292c) plus lighter and darker steps of those same hues for the
lighting. These are image files, not page chrome, so the token rule does not reach them — but
nothing here introduces a hue the house palette does not already carry.
"""
import os

OUT = os.path.join(os.path.dirname(__file__), '..', 'public', 'assets', 'img', 'spark')

# ── shared pieces ────────────────────────────────────────────────────────────

BOLD_DEFS = '''<defs>
<radialGradient id="bg" cx="50%" cy="45%" r="60%"><stop offset="0" stop-color="#e9f8ea"/><stop offset=".7" stop-color="#d4efd5"/><stop offset="1" stop-color="#d4efd5" stop-opacity="0"/></radialGradient>
<linearGradient id="gr" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5cc95a"/><stop offset=".55" stop-color="#2e9a2c"/><stop offset="1" stop-color="#1a6118"/></linearGradient>
<linearGradient id="go" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffe27a"/><stop offset=".5" stop-color="#f3b416"/><stop offset="1" stop-color="#c98a00"/></linearGradient>
<linearGradient id="lv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff6b93"/><stop offset="1" stop-color="#b0224f"/></linearGradient>
<linearGradient id="bl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5aa9dc"/><stop offset="1" stop-color="#1f5f8b"/></linearGradient>
<linearGradient id="wh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#e3ece6"/></linearGradient>
<linearGradient id="gl" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".75"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
<radialGradient id="sh" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#10292c" stop-opacity=".22"/><stop offset="1" stop-color="#10292c" stop-opacity="0"/></radialGradient>
</defs>'''

def bold_frame(body: str) -> str:
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160" role="img" aria-hidden="true">{BOLD_DEFS}'
            '<ellipse cx="120" cy="80" rx="112" ry="76" fill="url(#bg)"/>'
            '<circle cx="34" cy="34" r="10" fill="#7fc87c" opacity=".35"/><circle cx="210" cy="122" r="14" fill="#f3b416" opacity=".25"/>'
            '<ellipse cx="120" cy="142" rx="56" ry="8" fill="url(#sh)"/>'
            f'{body}{sparkles(((40, 60, 7, "#f3b416"), (200, 40, 9, "#237b22"), (188, 98, 5, "#e0245e"), (58, 116, 5, "#1f6fa3")))}</svg>')

def sparkles(items) -> str:
    out = ''
    for x, y, r, c in items:
        out += (f'<path d="M{x} {y-r} Q{x} {y} {x+r} {y} Q{x} {y} {x} {y+r} Q{x} {y} {x-r} {y} Q{x} {y} {x} {y-r}Z" fill="{c}"/>')
    return out

HOUSE_CONFETTI = (
    '<g>'
    '<rect x="44" y="40" width="10" height="4" rx="2" fill="#237b22" transform="rotate(-30 49 42)"/>'
    '<rect x="186" y="52" width="10" height="4" rx="2" fill="#f3b416" transform="rotate(40 191 54)"/>'
    '<rect x="196" y="112" width="9" height="4" rx="2" fill="#237b22" transform="rotate(-20 200 114)"/>'
    '<rect x="36" y="104" width="9" height="4" rx="2" fill="#e8892b" transform="rotate(25 40 106)"/>'
    '<circle cx="64" cy="24" r="2.5" fill="#e8892b"/><circle cx="176" cy="26" r="2.5" fill="#237b22"/>'
    '<circle cx="212" cy="80" r="2.5" fill="#f3b416"/><circle cx="28" cy="74" r="2.5" fill="#237b22"/>'
    '</g>')

def house_frame(body: str) -> str:
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160" role="img" aria-hidden="true">'
            '<rect width="240" height="160" rx="18" fill="#fbf1d8"/>'
            '<circle cx="120" cy="80" r="62" fill="#f6e3b4"/>'
            '<ellipse cx="120" cy="140" rx="48" ry="5" fill="#e3c98d"/>'
            f'{HOUSE_CONFETTI}{star(52, 70, 6, "#f3b416")}{star(190, 86, 7, "#237b22")}{star(176, 22, 5, "#e8892b")}{body}</svg>')

def star(cx, cy, r, c) -> str:
    import math
    pts = []
    for i in range(10):
        a = -math.pi / 2 + i * math.pi / 5
        rr = r if i % 2 == 0 else r * .45
        pts.append(f'{cx + rr * math.cos(a):.1f},{cy + rr * math.sin(a):.1f}')
    return f'<polygon points="{" ".join(pts)}" fill="{c}"/>'

# ── subjects ─────────────────────────────────────────────────────────────────
# Each is (bold body, house body), drawn in the 240×160 box around a centre of (120, 80).

ROCKET = (
    # bold: a glossy rocket climbing, flame below
    '<g transform="rotate(35 120 80)">'
    '<path d="M110 118 Q104 134 120 146 Q136 134 130 118Z" fill="url(#go)"/>'
    '<path d="M114 118 Q112 128 120 136 Q128 128 126 118Z" fill="#fff4c2"/>'
    '<path d="M100 104 L86 120 L104 116Z" fill="url(#lv)"/><path d="M140 104 L154 120 L136 116Z" fill="url(#lv)"/>'
    '<path d="M120 16 C142 36 146 74 138 118 L102 118 C94 74 98 36 120 16Z" fill="url(#wh)" stroke="#cfdcd4" stroke-width="1.5"/>'
    '<path d="M120 16 C130 25 136 36 139 48 L101 48 C104 36 110 25 120 16Z" fill="url(#gr)"/>'
    '<circle cx="120" cy="74" r="13" fill="url(#bl)" stroke="#fff" stroke-width="4"/>'
    '<ellipse cx="115" cy="69" rx="4" ry="3" fill="#fff" opacity=".7"/>'
    '<path d="M106 30 C104 54 104 86 108 112" stroke="url(#gl)" stroke-width="5" fill="none" stroke-linecap="round"/>'
    '<rect x="100" y="112" width="40" height="8" rx="3" fill="url(#gr)"/>'
    '</g>',
    # house: flat rocket
    '<g transform="rotate(30 120 80)">'
    '<path d="M112 116 Q108 130 120 140 Q132 130 128 116Z" fill="#e8892b"/>'
    '<path d="M102 102 L90 118 L106 114Z" fill="#237b22"/><path d="M138 102 L150 118 L134 114Z" fill="#237b22"/>'
    '<path d="M120 22 C140 40 144 76 136 116 L104 116 C96 76 100 40 120 22Z" fill="#fffaf0"/>'
    '<path d="M120 22 C129 30 134 40 137 50 L103 50 C106 40 111 30 120 22Z" fill="#f3b416"/>'
    '<circle cx="120" cy="76" r="11" fill="#237b22"/><circle cx="120" cy="76" r="6" fill="#fffaf0"/>'
    '</g>')

MEGAPHONE = (
    '<g transform="rotate(-12 120 80)">'
    '<path d="M78 70 L150 36 L150 124 L78 92Z" fill="url(#gr)"/>'
    '<path d="M150 36 L150 124 Q166 80 150 36Z" fill="#1a6118"/>'
    '<ellipse cx="152" cy="80" rx="12" ry="44" fill="url(#go)"/>'
    '<ellipse cx="152" cy="80" rx="6" ry="30" fill="#c98a00" opacity=".5"/>'
    '<rect x="62" y="66" width="20" height="30" rx="6" fill="url(#wh)" stroke="#cfdcd4" stroke-width="1.5"/>'
    '<path d="M86 100 L96 128 Q98 134 104 132 L110 130 Q114 128 112 122 L104 98Z" fill="url(#lv)"/>'
    '<path d="M88 72 L146 46" stroke="url(#gl)" stroke-width="6" stroke-linecap="round"/>'
    '<path d="M178 52 Q192 60 194 74 M182 82 Q200 84 208 92 M176 108 Q188 112 192 124" stroke="#f3b416" stroke-width="5" fill="none" stroke-linecap="round"/>'
    '</g>',
    '<g transform="rotate(-12 120 80)">'
    '<path d="M80 70 L148 40 L148 120 L80 92Z" fill="#237b22"/>'
    '<ellipse cx="150" cy="80" rx="10" ry="40" fill="#f3b416"/>'
    '<rect x="66" y="66" width="18" height="28" rx="5" fill="#fffaf0"/>'
    '<path d="M88 98 L98 124 L110 120 L102 96Z" fill="#e8892b"/>'
    '<path d="M174 56 Q188 64 190 78 M178 86 Q196 88 204 96" stroke="#e8892b" stroke-width="4" fill="none" stroke-linecap="round"/>'
    '</g>')

TARGET = (
    '<circle cx="114" cy="84" r="54" fill="url(#lv)"/><circle cx="114" cy="84" r="42" fill="url(#wh)"/>'
    '<circle cx="114" cy="84" r="30" fill="url(#lv)"/><circle cx="114" cy="84" r="18" fill="url(#wh)"/>'
    '<circle cx="114" cy="84" r="8" fill="url(#go)"/>'
    '<path d="M76 54 A48 48 0 0 1 120 34" stroke="#fff" stroke-opacity=".6" stroke-width="5" fill="none" stroke-linecap="round"/>'
    '<path d="M116 82 L178 26" stroke="url(#gr)" stroke-width="7" stroke-linecap="round"/>'
    '<path d="M170 20 L190 18 L186 36 L176 32Z" fill="url(#go)"/><path d="M178 26 L192 12 M180 30 L196 24" stroke="#f3b416" stroke-width="5" stroke-linecap="round"/>',
    '<circle cx="114" cy="84" r="50" fill="#e8892b"/><circle cx="114" cy="84" r="38" fill="#fffaf0"/>'
    '<circle cx="114" cy="84" r="26" fill="#e8892b"/><circle cx="114" cy="84" r="14" fill="#fffaf0"/>'
    '<circle cx="114" cy="84" r="6" fill="#237b22"/>'
    '<path d="M116 82 L176 28" stroke="#237b22" stroke-width="6" stroke-linecap="round"/>'
    '<path d="M168 22 L186 20 L182 36Z" fill="#237b22"/>')

TROPHY = (
    '<path d="M86 42 Q60 44 66 70 Q72 88 96 88" stroke="url(#go)" stroke-width="9" fill="none"/>'
    '<path d="M154 42 Q180 44 174 70 Q168 88 144 88" stroke="url(#go)" stroke-width="9" fill="none"/>'
    '<path d="M84 30 L156 30 L152 70 Q148 98 120 102 Q92 98 88 70Z" fill="url(#go)"/>'
    '<path d="M94 38 Q96 72 108 92" stroke="url(#gl)" stroke-width="7" fill="none" stroke-linecap="round"/>'
    '<rect x="112" y="100" width="16" height="16" fill="#c98a00"/>'
    '<rect x="94" y="114" width="52" height="22" rx="5" fill="url(#gr)"/>'
    '<rect x="106" y="120" width="28" height="9" rx="2" fill="url(#go)"/>'
    + '<path d="M120 46 L125 58 L138 59 L128 67 L131 80 L120 73 L109 80 L112 67 L102 59 L115 58Z" fill="#fff6cf"/>',
    '<path d="M88 44 Q66 46 70 68 Q74 84 96 86" stroke="#c99a06" stroke-width="7" fill="none"/>'
    '<path d="M152 44 Q174 46 170 68 Q166 84 144 86" stroke="#c99a06" stroke-width="7" fill="none"/>'
    '<path d="M86 32 L154 32 L150 70 Q146 96 120 100 Q94 96 90 70Z" fill="#f3b416"/>'
    '<path d="M120 32 L154 32 L150 70 Q146 96 120 100Z" fill="#e5a50f"/>'
    '<rect x="112" y="98" width="16" height="16" fill="#e5a50f"/>'
    '<rect x="96" y="112" width="48" height="22" rx="4" fill="#237b22"/>'
    '<rect x="108" y="118" width="24" height="9" rx="2" fill="#f3b416"/>')

UNLOCK = (
    '<path d="M98 66 V46 Q98 22 122 22 Q146 22 146 46 V50" stroke="url(#wh)" stroke-width="12" fill="none" stroke-linecap="round"/>'
    '<path d="M98 66 V46 Q98 22 122 22 Q146 22 146 46 V50" stroke="#b8c7bf" stroke-width="2" fill="none" stroke-linecap="round" transform="translate(0 4)" opacity=".5"/>'
    '<rect x="78" y="62" width="88" height="70" rx="16" fill="url(#gr)"/>'
    '<path d="M88 72 Q90 100 98 122" stroke="url(#gl)" stroke-width="6" fill="none" stroke-linecap="round"/>'
    '<circle cx="122" cy="92" r="10" fill="url(#go)"/><rect x="118" y="96" width="8" height="18" rx="4" fill="url(#go)"/>'
    '<g><circle cx="186" cy="110" r="16" fill="url(#go)"/><circle cx="186" cy="110" r="11" fill="none" stroke="#c98a00" stroke-width="2"/>'
    '<text x="186" y="115" font-size="14" font-weight="800" text-anchor="middle" fill="#7a5600" font-family="sans-serif">₦</text></g>'
    '<g><circle cx="56" cy="114" r="12" fill="url(#go)"/><text x="56" y="118" font-size="11" font-weight="800" text-anchor="middle" fill="#7a5600" font-family="sans-serif">₦</text></g>',
    '<path d="M100 66 V48 Q100 26 122 26 Q144 26 144 48 V52" stroke="#c9cfc6" stroke-width="10" fill="none" stroke-linecap="round"/>'
    '<rect x="80" y="62" width="84" height="66" rx="12" fill="#237b22"/>'
    '<rect x="122" y="62" width="42" height="66" rx="12" fill="#1a6118"/><rect x="122" y="62" width="12" height="66" fill="#1a6118"/>'
    '<circle cx="122" cy="90" r="9" fill="#f3b416"/><rect x="118" y="94" width="8" height="16" rx="3" fill="#f3b416"/>'
    '<circle cx="186" cy="112" r="13" fill="#f3b416"/><circle cx="58" cy="116" r="10" fill="#f3b416"/>')

GIFT = (
    # bold: a gift box in three-quarter view, the box and lid lit from the top left, one clean
    # gold ribbon crossing every face, a full bow with two loops and two tails. Redrawn 20 Nov
    # 2026 (owner: "improve the gift box") — no patterned ribbon, nothing that blurs when small.
    '<defs>'
    '<linearGradient id="gF" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3fae3c"/><stop offset="1" stop-color="#1f7a1d"/></linearGradient>'
    '<linearGradient id="gS" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1f7a1d"/><stop offset="1" stop-color="#14520f"/></linearGradient>'
    '<linearGradient id="gT" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7ad877"/><stop offset="1" stop-color="#36a333"/></linearGradient>'
    '<linearGradient id="rF" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ffe27a"/><stop offset=".5" stop-color="#f3b416"/><stop offset="1" stop-color="#d99a06"/></linearGradient>'
    '<linearGradient id="rS" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#d99a06"/><stop offset="1" stop-color="#b07a00"/></linearGradient>'
    '<linearGradient id="bw" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe9a0"/><stop offset=".55" stop-color="#f3b416"/><stop offset="1" stop-color="#c98a00"/></linearGradient>'
    '</defs>'
    # body: front and side faces
    '<path d="M76 74 L142 83 L142 136 L76 127Z" fill="url(#gF)"/>'
    '<path d="M142 83 L172 72 L172 124 L142 136Z" fill="url(#gS)"/>'
    # ribbon down the body
    '<path d="M103 78 L115 79.6 L115 132.4 L103 130.8Z" fill="url(#rF)"/>'
    '<path d="M153 79 L161 76 L161 128 L153 131.6Z" fill="url(#rS)"/>'
    # lid: top, front band, side band
    '<path d="M70 62 L146 72 L178 60 L102 50Z" fill="url(#gT)"/>'
    '<path d="M70 62 L146 72 L146 85 L70 75Z" fill="url(#gF)"/>'
    '<path d="M146 72 L178 60 L178 73 L146 85Z" fill="url(#gS)"/>'
    # ribbon over the lid: on its front band, its side band and across the top both ways
    '<path d="M101 66 L115 67.9 L115 80.9 L101 79Z" fill="url(#rF)"/>'
    '<path d="M154 69 L163 65.6 L163 78.6 L154 82Z" fill="url(#rS)"/>'
    '<path d="M101 66 L115 67.9 L148 55.5 L134 53.7Z" fill="#f7c63a"/>'
    '<path d="M83 56.5 L91 53.6 L167 63.6 L159 66.5Z" fill="#f7c63a"/>'
    # light: a soft gloss on the front and the lid's front edge
    '<path d="M82 82 Q84 104 90 122" stroke="#fff" stroke-opacity=".28" stroke-width="5" fill="none" stroke-linecap="round"/>'
    '<path d="M76 66 L140 74.5" stroke="#fff" stroke-opacity=".45" stroke-width="2" stroke-linecap="round"/>'
    # the bow: two loops, two tails, a knot
    '<path d="M124 58 C104 52 88 30 102 26 C114 22 122 42 124 58Z" fill="url(#bw)"/>'
    '<path d="M124 58 C144 50 160 30 148 24 C136 18 126 40 124 58Z" fill="url(#bw)"/>'
    '<path d="M110 30 C114 36 118 46 122 54" stroke="#c98a00" stroke-opacity=".55" stroke-width="2" fill="none" stroke-linecap="round"/>'
    '<path d="M144 29 C138 36 132 46 126 54" stroke="#c98a00" stroke-opacity=".55" stroke-width="2" fill="none" stroke-linecap="round"/>'
    '<path d="M121 60 L110 78 L116 77 L119 83Z" fill="url(#bw)"/>'
    '<path d="M127 60 L140 76 L134 76 L132 82Z" fill="url(#bw)"/>'
    '<ellipse cx="124" cy="58" rx="7" ry="6" fill="url(#bw)"/>'
    '<ellipse cx="122" cy="56" rx="2.5" ry="1.8" fill="#fff" opacity=".7"/>'
    '<path d="M104 30 C108 28 112 32 114 36" stroke="#fff" stroke-opacity=".7" stroke-width="2" fill="none" stroke-linecap="round"/>'
    # a few coins and sparks bursting out
    '<circle cx="64" cy="44" r="8" fill="url(#go)"/><circle cx="64" cy="44" r="5" fill="none" stroke="#c98a00" stroke-width="1.2"/>'
    '<circle cx="186" cy="40" r="6" fill="url(#go)"/><circle cx="194" cy="86" r="4" fill="url(#lv)"/>',
    # house: the same gift, flat — two-tone faces, a solid ribbon, the bow as two shapes
    '<path d="M78 76 L142 84 L142 134 L78 126Z" fill="#237b22"/>'
    '<path d="M142 84 L170 74 L170 122 L142 134Z" fill="#1a6118"/>'
    '<path d="M104 79 L116 80.5 L116 130.5 L104 129Z" fill="#f3b416"/>'
    '<path d="M152 80 L160 77 L160 126 L152 129.3Z" fill="#e5a50f"/>'
    '<path d="M72 64 L146 73 L176 62 L102 53Z" fill="#2e8f2c"/>'
    '<path d="M72 64 L146 73 L146 85 L72 76Z" fill="#237b22"/>'
    '<path d="M146 73 L176 62 L176 74 L146 85Z" fill="#1a6118"/>'
    '<path d="M102 68 L116 69.7 L116 81.7 L102 80Z" fill="#f3b416"/>'
    '<path d="M102 68 L116 69.7 L148 58 L134 56.4Z" fill="#f3b416"/>'
    '<path d="M85 59 L93 56.2 L165 65 L157 67.8Z" fill="#f3b416"/>'
    '<path d="M124 60 C106 54 92 34 104 30 C114 27 122 44 124 60Z" fill="#f3b416"/>'
    '<path d="M124 60 C142 52 156 34 146 28 C136 24 126 42 124 60Z" fill="#e5a50f"/>'
    '<circle cx="124" cy="60" r="6" fill="#e5a50f"/>')

COINS = (
    ''.join(f'<ellipse cx="100" cy="{124 - i*12}" rx="30" ry="9" fill="#c98a00"/><ellipse cx="100" cy="{120 - i*12}" rx="30" ry="9" fill="url(#go)"/>' for i in range(5))
    + ''.join(f'<ellipse cx="150" cy="{124 - i*12}" rx="26" ry="8" fill="#c98a00"/><ellipse cx="150" cy="{120 - i*12}" rx="26" ry="8" fill="url(#go)"/>' for i in range(3))
    + '<path d="M168 70 L168 30 M154 44 L168 28 L182 44" stroke="url(#gr)" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
    '<text x="100" y="75" font-size="13" font-weight="800" text-anchor="middle" fill="#7a5600" font-family="sans-serif">₦</text>',
    ''.join(f'<ellipse cx="100" cy="{124 - i*12}" rx="28" ry="8" fill="#e5a50f"/><ellipse cx="100" cy="{120 - i*12}" rx="28" ry="8" fill="#f3b416"/>' for i in range(5))
    + ''.join(f'<ellipse cx="148" cy="{124 - i*12}" rx="24" ry="7" fill="#e5a50f"/><ellipse cx="148" cy="{120 - i*12}" rx="24" ry="7" fill="#f3b416"/>' for i in range(3))
    + '<path d="M168 70 L168 32 M156 46 L168 32 L180 46" stroke="#237b22" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>')

BALLOT = (
    '<rect x="70" y="72" width="100" height="64" rx="12" fill="url(#gr)"/>'
    '<rect x="70" y="72" width="100" height="16" rx="8" fill="#1a6118"/>'
    '<rect x="96" y="76" width="48" height="6" rx="3" fill="#0f3d0e"/>'
    '<g transform="rotate(-8 120 52)"><rect x="98" y="22" width="44" height="58" rx="6" fill="url(#wh)" stroke="#cfdcd4" stroke-width="1.5"/>'
    '<path d="M120 60 C108 50 106 40 114 38 Q118 37 120 42 Q122 37 126 38 C134 40 132 50 120 60Z" fill="url(#lv)"/></g>'
    '<path d="M80 92 Q82 112 88 128" stroke="url(#gl)" stroke-width="6" fill="none" stroke-linecap="round"/>'
    '<path d="M130 112 l8 8 l16 -18" stroke="#fff" stroke-width="6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    '<rect x="72" y="72" width="96" height="62" rx="8" fill="#237b22"/>'
    '<rect x="96" y="76" width="48" height="6" rx="3" fill="#1a6118"/>'
    '<g transform="rotate(-8 120 52)"><rect x="100" y="24" width="40" height="54" rx="4" fill="#fffaf0"/>'
    '<path d="M120 58 C110 50 108 42 114 40 Q118 39 120 44 Q122 39 126 40 C132 42 130 50 120 58Z" fill="#e0245e"/></g>'
    '<path d="M130 110 l7 7 l14 -16" stroke="#f3b416" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>')

# ── the Christmas set (owner, 20 Nov 2026: "add them now, it's 10 days till December") ──
# Pop-up pictures for the season, app style only. Saved as xmas-{name}.svg; a generated file of
# the same name (xmas-santa.webp, …) replaces the drawing, and Santa exists only as a file.
XRED = ('<linearGradient id="xr" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f05a5a"/>'
        '<stop offset="1" stop-color="#a3101c"/></linearGradient>')

def holly(x, y, s=1.0):
    return (f'<g transform="translate({x} {y}) scale({s})">'
            '<path d="M0 0 C-8 -10 -22 -8 -30 -16 C-26 -6 -28 2 -20 6 C-14 2 -8 4 0 0Z" fill="url(#gr)"/>'
            '<path d="M0 0 C6 -12 20 -14 26 -24 C26 -12 30 -6 24 0 C16 -2 10 2 0 0Z" fill="url(#gr)"/>'
            '<circle cx="-2" cy="2" r="5" fill="url(#xr)"/><circle cx="7" cy="4" r="5" fill="url(#xr)"/>'
            '<circle cx="2" cy="-5" r="5" fill="url(#xr)"/><circle cx="0.5" cy="0.5" r="1.4" fill="#fff" opacity=".7"/></g>')

XMAS = {
    'mic': (
        '<rect x="98" y="16" width="44" height="64" rx="22" fill="url(#go)"/>'
        + ''.join(f'<rect x="104" y="{26 + i*9}" width="32" height="4.5" rx="2.2" fill="#1a6118"/>' for i in range(6))
        + '<path d="M104 24 Q100 50 106 72" stroke="url(#gl)" stroke-width="4" fill="none" stroke-linecap="round"/>'
        '<path d="M92 54 Q92 90 120 90 Q148 90 148 54" stroke="url(#go)" stroke-width="6" fill="none" stroke-linecap="round"/>'
        '<rect x="116" y="90" width="8" height="30" fill="url(#go)"/>'
        '<ellipse cx="120" cy="126" rx="34" ry="9" fill="url(#gr)"/><ellipse cx="120" cy="122" rx="30" ry="6" fill="url(#go)"/>'
        + holly(146, 112, .9)),
    'tree': (
        '<rect x="104" y="112" width="12" height="16" fill="#8a5a1c"/>'
        '<path d="M110 22 L138 64 L126 64 L146 96 L132 96 L150 120 L70 120 L88 96 L74 96 L94 64 L82 64Z" fill="url(#gr)"/>'
        '<path d="M110 22 L96 52" stroke="url(#gl)" stroke-width="4" stroke-linecap="round"/>'
        '<path d="M110 14 L113 21 L121 21 L115 26 L117 34 L110 29 L103 34 L105 26 L99 21 L107 21Z" fill="url(#go)"/>'
        '<circle cx="104" cy="70" r="5" fill="url(#go)"/><circle cx="122" cy="88" r="5" fill="url(#go)"/><circle cx="96" cy="104" r="5" fill="url(#go)"/>'
        # the present, front right
        '<rect x="136" y="98" width="46" height="36" rx="5" fill="url(#xr)"/><rect x="132" y="90" width="54" height="12" rx="4" fill="url(#xr)"/>'
        '<rect x="155" y="90" width="8" height="44" fill="url(#go)"/>'
        '<path d="M159 90 C146 74 138 84 146 90Z" fill="url(#go)"/><path d="M159 90 C172 74 180 84 172 90Z" fill="url(#go)"/>'
        # the bell, front left, with a red bow
        '<path d="M58 132 C58 108 64 96 76 94 C88 96 94 108 94 132Z" fill="url(#go)"/>'
        '<ellipse cx="76" cy="133" rx="20" ry="5" fill="#c98a00"/><circle cx="76" cy="138" r="5" fill="url(#go)"/>'
        '<path d="M76 92 C62 80 52 92 64 98Z" fill="url(#xr)"/><path d="M76 92 C90 80 100 92 88 98Z" fill="url(#xr)"/>'
        '<circle cx="76" cy="93" r="4" fill="url(#xr)"/>'
        '<path d="M66 108 Q64 118 66 126" stroke="#fff" stroke-opacity=".6" stroke-width="3" fill="none" stroke-linecap="round"/>'),
    'trophy': (
        '<path d="M86 34 Q60 36 66 62 Q72 80 96 80" stroke="url(#go)" stroke-width="9" fill="none"/>'
        '<path d="M154 34 Q180 36 174 62 Q168 80 144 80" stroke="url(#go)" stroke-width="9" fill="none"/>'
        '<path d="M84 22 L156 22 L152 62 Q148 90 120 94 Q92 90 88 62Z" fill="url(#go)"/>'
        '<ellipse cx="120" cy="24" rx="36" ry="5" fill="#c98a00" opacity=".5"/>'
        '<path d="M94 30 Q96 64 108 84" stroke="url(#gl)" stroke-width="7" fill="none" stroke-linecap="round"/>'
        '<rect x="112" y="92" width="16" height="14" fill="#c98a00"/>'
        '<path d="M120 98 C104 86 90 96 102 104 C108 108 116 104 120 100Z" fill="url(#xr)"/>'
        '<path d="M120 98 C136 86 150 96 138 104 C132 108 124 104 120 100Z" fill="url(#xr)"/>'
        '<path d="M117 101 L108 118 L114 116 L116 122Z" fill="url(#xr)"/><path d="M123 101 L132 118 L126 116 L124 122Z" fill="url(#xr)"/>'
        '<circle cx="120" cy="100" r="5" fill="url(#xr)"/>'
        '<rect x="94" y="112" width="52" height="22" rx="6" fill="url(#gr)"/><rect x="90" y="130" width="60" height="8" rx="4" fill="url(#go)"/>'),
}

SUBJECTS = {'rocket': ROCKET, 'megaphone': MEGAPHONE, 'target': TARGET, 'trophy': TROPHY,
            'unlock': UNLOCK, 'gift': GIFT, 'coins': COINS, 'ballot': BALLOT}

if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for name, (bold, house) in SUBJECTS.items():
        for style, svg in (('bold', bold_frame(bold)), ('house', house_frame(house))):
            with open(os.path.join(OUT, f'{name}-{style}.svg'), 'w', encoding='utf-8') as f:
                f.write(svg + '\n')
    for name, body in XMAS.items():
        svg = bold_frame(body).replace('</defs>', XRED + '</defs>', 1)
        with open(os.path.join(OUT, f'xmas-{name}.svg'), 'w', encoding='utf-8') as f:
            f.write(svg + '\n')
    print(f'{len(SUBJECTS) * 2 + len(XMAS)} files in {os.path.normpath(OUT)}')
