import { buildCsv } from './format'
import type { ReportFile } from '../mock/types'

/** Prototype: builds the CSV in the browser. The real app streams it from an authorized endpoint. */
export function downloadCsv(file: ReportFile) {
  const csv = buildCsv(
    file.header,
    file.rows.map((r) => r.cells),
  )
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = file.name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function csvSizeLabel(file: ReportFile): string {
  const bytes = new TextEncoder().encode(
    buildCsv(
      file.header,
      file.rows.map((r) => r.cells),
    ),
  ).length
  return `CSV · ${Math.max(1, Math.round(bytes / 1024))} KB`
}
