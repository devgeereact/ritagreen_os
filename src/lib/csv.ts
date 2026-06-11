import { saveAs } from "file-saver"

// Export an array of rows (objects) to a downloadable CSV.
export function exportCsv(filename: string, rows: Record<string, string | number>[]) {
  if (rows.length === 0) return
  const headers = Object.keys(rows[0])
  const escape = (v: string | number) => {
    const s = String(v ?? "")
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  const csv = [
    headers.join(","),
    ...rows.map((r) => headers.map((h) => escape(r[h])).join(",")),
  ].join("\n")
  saveAs(new Blob([csv], { type: "text/csv;charset=utf-8" }), filename)
}
