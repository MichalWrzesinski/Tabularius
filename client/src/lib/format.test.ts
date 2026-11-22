import { describe, expect, it } from 'vitest'
import { buildCsv, formatDate, formatDateTime, formatLongDate, formatPln, formatTime, guardCsvText, percentOf } from './format'

describe('formatPln', () => {
  it('formats grosze with a decimal comma and optional grouping', () => {
    expect(formatPln(128900)).toBe('1 289,00')
    expect(formatPln(128900, false)).toBe('1289,00')
    expect(formatPln(-14900, false)).toBe('-149,00')
    expect(formatPln(5)).toBe('0,05')
  })
  it('rejects non-integer amounts', () => {
    expect(() => formatPln(1.5)).toThrow()
  })
})

describe('percentOf (RPT-DISC boundary table)', () => {
  const tier = (b: number) => (b >= 10_000_000 ? 700 : b >= 5_000_000 ? 500 : b >= 1_000_000 ? 300 : 0)
  it.each([
    [999_999, '0,00'],
    [1_000_000, '300,00'],
    [4_999_999, '1500,00'],
    [5_000_000, '2500,00'],
    [5_000_010, '2500,01'],
    [9_999_999, '5000,00'],
    [10_000_000, '7000,00'],
    [-12_000, '0,00'],
  ])('basis %i grosze → %s', (basis, expected) => {
    expect(formatPln(percentOf(basis, tier(basis)), false)).toBe(expected)
  })
})

describe('CSV', () => {
  it.each([
    ['=SUMA(A1)', "'=SUMA(A1)"],
    ['+48 Auto', "'+48 Auto"],
    ['-x', "'-x"],
    ['@a', "'@a"],
    ['\tx', "'\tx"],
    ['＝1', "'＝1"],
    ['Bosch', 'Bosch'],
  ])('guards %j', (value, expected) => {
    expect(guardCsvText(value)).toBe(expected)
  })
  it('writes BOM, semicolons, CRLF and quotes', () => {
    expect(buildCsv(['a', 'b'], [['x;y', 'q"t']])).toBe('﻿a;b\r\n"x;y";"q""t"\r\n')
  })
})

describe('dates in Europe/Warsaw', () => {
  it('formats UTC instants in Warsaw time, including DST', () => {
    expect(formatDateTime('2026-11-15T09:42:00Z')).toBe('15.11.2026 10:42')
    expect(formatTime('2026-07-01T09:42:00Z')).toBe('11:42')
    expect(formatDate('2026-10-31T23:30:00Z')).toBe('01.11.2026')
    expect(formatLongDate('2026-11-15T09:42:00Z')).toBe('15 listopada 2026')
  })
})
