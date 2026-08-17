# Bundled fonts

`Montserrat-*.ttf` and `PlayfairDisplay-Bold.ttf` — the site's own two typefaces, as
static instances, used **server-side** by `FlierService::png()`.

## Why they are committed

The flier has to exist as a **raster image at a URL**, because that is what an
`og:image` is: WhatsApp, Facebook and X do not render SVG in a link preview, and a
crawler cannot run the browser-side canvas that produces the download. So the PNG has
to be rendered by PHP, and GD's `imagettftext()` needs a TrueType file on disk. A
shared cPanel host frequently has no fonts installed at all, so relying on the system
is how the flier silently becomes DejaVu — or a bitmap face — on exactly the
deployment nobody can inspect.

## Why they are static instances rather than the variable fonts

Google Fonts now ships Montserrat and Playfair Display only as variable fonts, and GD
has no way to select a weight axis. Measured: FreeType renders a variable font's
**lightest** instance, so `Montserrat[wght].ttf` came out as Thin — a 26px "VOTE NOW"
kicker in Thin is close to invisible. These were produced with
`fontTools.varLib.instancer` at `wght` 400 / 600 / 700.

## Subsetting

Trimmed to the Latin ranges every African Latin orthography needs, which is the part
that would break quietly if trimmed too far. Verified rendering:

| | |
| --- | --- |
| Yoruba | Ọlásùnkànmí Ṣẹ́gun |
| Akan / Ewe | Ɔdɔ Nyankopɔn Ŋwae |
| Hausa | Ɓalarabe Ɗanjuma Ƙano |
| Kikuyu | Wangarĩ Mũthoni |
| French / Portuguese | Aïssatou Diané Coté · João Conceição |
| Marks and currency | · — “ ” … ₦ ₵ |

Ranges kept: `U+0020-007E, U+00A0-00FF, U+0100-017F, U+0180-024F, U+0250-02AF,
U+1E00-1EFF, U+2000-206F, U+20A0-20BF, U+2122, U+2190-21FF, U+25A0-25FF`.
720 KB for all four faces.

## Licence

Both are under the SIL Open Font License 1.1, which permits redistribution and
bundling. Full texts: `OFL-Montserrat.txt`, `OFL-PlayfairDisplay.txt`.

---

## `AGText-*` and `AGMono-Bold` — the PDF faces

Added for the ticket PDFs (`AfricaGates\Services\TicketPdf`). Derived from **DejaVu Sans**
and **DejaVu Sans Mono Bold**, trimmed with this repository's own subsetter
(`TrueType::subset($gids, standalone: true)`) to the ranges listed above.

### Why not the brand face

Measured, not assumed: the `DMSans-*.ttf` files in this directory have **no glyph** for
`Ọ ọ Ẹ ẹ Ṣ ṣ`, none for `Ɓ Ɗ Ƙ Ɔ`, and none for `₦`. Google's own TTF delivery serves the
same latin-only cut. A ticket for *Ọlásùnkànmí Ṣẹ́gun* set in it prints holes through the
middle of somebody's name and loses the currency off the price — which on this platform is
not a cosmetic defect.

`Pdf::font()` takes a fallback chain precisely so a brand face can be reinstated later
without any of the ticket code changing: register DM Sans with these as its fallback and
every character it *can* set comes from it.

**The same gap affects `FlierService::png()`**, which still sets social images in DM Sans.
That is a separate fix and is not addressed here.

### Licence

DejaVu fonts are released under a permissive Bitstream Vera / Arev derivative licence that
allows redistribution and modification. Full text: `LICENCE-DejaVu.txt`.
