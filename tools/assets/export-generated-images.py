#!/usr/bin/env python3
"""Create responsive transparent WebP exports from an image-generation source map.

Usage: python3 tools/assets/export-generated-images.py --sources /path/to/sources.json
The JSON maps approved utility keys to generated PNG masters. Requires ImageMagick.
Existing SVG interface icons and original default photographs are never modified.
"""
import argparse
import json
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sources', type=Path, required=True)
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[2] / 'public/assets/img/utility')
    args = parser.parse_args()
    sources = json.loads(args.sources.read_text())
    approved = json.loads((args.output / 'manifest.json').read_text())['assets']
    for key in approved:
        if key not in sources:
            raise SystemExit(f'Missing generated master: {key}')
        source = Path(sources[key]).resolve(strict=True)
        for width in (256, 512, 960):
            output = args.output / f'{key}-{width}.webp'
            if output.exists() and output.stat().st_mtime >= source.stat().st_mtime:
                continue
            subprocess.run(['magick', str(source), '-resize', f'{width}x{width}', '-quality', '86',
                            '-define', 'webp:alpha-quality=100', str(output)], check=True)
    print(f'Exported {len(approved)} generated utility images in three responsive sizes.')


if __name__ == '__main__':
    main()
