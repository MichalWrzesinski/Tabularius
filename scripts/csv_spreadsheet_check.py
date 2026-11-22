"""Opens report CSVs in LibreOffice Calc (headless, Polish locale) and checks how cells were imported (RPT-OUT-2/4).

Usage (from the repository root, after `make csv-samples`):  make csv-check
Checks: amounts and quantities become numbers; formula-like text stays text; no cell becomes a formula.
"""
import pathlib
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

NS = {
    'table': 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
    'office': 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
    'text': 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
}
# separator ;, text delimiter ", UTF-8, first line 1, default column formats, language pl-PL (1045),
# quoted fields as text = false, detect special numbers = true
FILTER = 'CSV:59,34,76,1,,1045,false,true'


def cells(fods: pathlib.Path):
    root = ET.parse(fods).getroot()
    rows = []
    for row in root.iter(f"{{{NS['table']}}}table-row"):
        values = []
        for cell in row.findall('table:table-cell', NS):
            repeat = int(cell.get(f"{{{NS['table']}}}number-columns-repeated", '1'))
            text = ''.join(cell.itertext()).strip()
            kind = cell.get(f"{{{NS['office']}}}value-type", 'empty')
            formula = cell.get(f"{{{NS['table']}}}formula")
            values.extend([(kind, text, formula)] * min(repeat, 50))
        rows.append(values)
    return rows


def main(paths):
    failures = []
    with tempfile.TemporaryDirectory() as out:
        for path in map(pathlib.Path, paths):
            subprocess.run(['soffice', '-env:UserInstallation=file:///tmp/lo', '--headless', '--norestore', f'--infilter={FILTER}', '--convert-to', 'fods', '--outdir', out, str(path)],
                           check=True, capture_output=True)
            rows = cells(pathlib.Path(out) / (path.stem + '.fods'))
            header = [t for _, t, _ in rows[0]]
            amount_cols = [i for i, h in enumerate(header) if h.endswith('_pln') or h in ('ilosc', 'wartosc')]
            numbers = guarded = 0
            for r in rows[1:]:
                if not r or r[0][1] == 'meta':
                    continue
                for i, (kind, text, formula) in enumerate(r):
                    if formula:
                        failures.append(f'{path.name}: cell became a formula: {formula}')
                    if text.startswith("'"):
                        guarded += 1
                        if kind != 'string':
                            failures.append(f'{path.name}: guarded text imported as {kind}: {text}')
                    if i in amount_cols and text and any(ch.isdigit() for ch in text) and not text.startswith(('od ', "'")) and '-' not in text[1:]:
                        if kind == 'float':
                            numbers += 1
                        else:
                            failures.append(f'{path.name}: amount not numeric ({kind}): {text}')
            print(f'{path.name}: {numbers} numeric amount cells, {guarded} guarded text cells, no formulas')
    if failures:
        print('\n'.join(failures))
        sys.exit(1)


if __name__ == '__main__':
    main(sys.argv[1:])
