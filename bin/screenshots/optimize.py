"""Reduce las capturas a 256 colores sin tramado (PNG optimizado, unos 100 KB cada una). Requiere Pillow.

Uso: python3 bin/screenshots/optimize.py <directorio con screenshot-N.png>
"""
import glob
import sys

from PIL import Image

for path in sorted(glob.glob(sys.argv[1] + '/screenshot-*.png')):
    Image.open(path).convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(path, optimize=True)
