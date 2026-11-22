// Formatting helpers. Money is always an integer number of grosze (never a binary float of złoty).

const MONTHS_GEN = [
  'stycznia',
  'lutego',
  'marca',
  'kwietnia',
  'maja',
  'czerwca',
  'lipca',
  'sierpnia',
  'września',
  'października',
  'listopada',
  'grudnia',
]

/** `group: false` for CSV cells: spreadsheets read „1289,00” as a number, but not „1 289,00”. */
export function formatPln(grosze: number, group = true): string {
  if (!Number.isSafeInteger(grosze)) throw new Error('grosze must be a safe integer')
  const sign = grosze < 0 ? '-' : ''
  const abs = Math.abs(grosze)
  const digits = Math.trunc(abs / 100).toString()
  const zl = !group ? digits : digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  const gr = (abs % 100).toString().padStart(2, '0')
  return `${sign}${zl},${gr}`
}

/** basis × rate (basis points), rounded half up to 1 grosz. Inputs are integers. */
export function percentOf(grosze: number, basisPoints: number): number {
  if (grosze <= 0) return 0
  return Math.floor((grosze * basisPoints + 5000) / 10000)
}

function parts(iso: string) {
  const d = new Date(iso)
  const f = new Intl.DateTimeFormat('pl-PL', {
    timeZone: 'Europe/Warsaw',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).formatToParts(d)
  const get = (t: string) => f.find((p) => p.type === t)?.value ?? ''
  return { y: get('year'), m: get('month'), d: get('day'), h: get('hour'), min: get('minute') }
}

export function formatDateTime(iso: string): string {
  const p = parts(iso)
  return `${p.d}.${p.m}.${p.y} ${p.h}:${p.min}`
}

export function formatDate(iso: string): string {
  const p = parts(iso)
  return `${p.d}.${p.m}.${p.y}`
}

export function formatTime(iso: string): string {
  const p = parts(iso)
  return `${p.h}:${p.min}`
}

export function formatLongDate(iso: string): string {
  const p = parts(iso)
  return `${Number(p.d)} ${MONTHS_GEN[Number(p.m) - 1]} ${p.y}`
}

/** CSV text cell guard against formula injection (RPT-OUT-4). Numeric cells are not passed here. */
export function guardCsvText(value: string): string {
  return /^[=+\-@\t\r＝＋－＠]/.test(value) ? `'${value}` : value
}

function quote(value: string): string {
  return /[";\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

export function buildCsv(header: string[], rows: string[][]): string {
  const lines = [header, ...rows].map((r) => r.map(quote).join(';'))
  return '﻿' + lines.join('\r\n') + '\r\n'
}
