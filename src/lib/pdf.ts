import { jsPDF } from "jspdf"
import autoTable from "jspdf-autotable"
import { COMPANY, BILLING } from "@/lib/company"
import { DIVISION_LABELS } from "@/lib/format"
import type { Invoice, Quote } from "@/lib/types"

const GREEN: [number, number, number] = [46, 125, 50]
const INK: [number, number, number] = [34, 49, 31]
const MUTED: [number, number, number] = [110, 120, 110]

function money(pence: number): string {
  return "£" + (pence / 100).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

type DocMeta = {
  kind: "INVOICE" | "QUOTE"
  number: string
  date: string
  client: string | null
  division: string
  description: string
  net: number
  vat: number
  total: number
  status: string
  validityNote: string
}

function build(meta: DocMeta): jsPDF {
  const doc = new jsPDF({ unit: "pt", format: "a4" })
  const W = doc.internal.pageSize.getWidth()
  const M = 40

  // Header band
  doc.setFillColor(...GREEN)
  doc.rect(0, 0, W, 90, "F")
  doc.setTextColor(255, 255, 255)
  doc.setFont("helvetica", "bold").setFontSize(24)
  doc.text("RitaGreen", M, 48)
  doc.setFont("helvetica", "normal").setFontSize(9)
  doc.text(COMPANY.promise.slice(0, 64), M, 66)
  doc.setFont("helvetica", "bold").setFontSize(20)
  doc.text(meta.kind, W - M, 44, { align: "right" })
  doc.setFont("helvetica", "normal").setFontSize(11)
  doc.text(meta.number, W - M, 64, { align: "right" })

  // From / meta
  let y = 124
  doc.setTextColor(...INK).setFont("helvetica", "bold").setFontSize(10)
  doc.text(COMPANY.legalName, M, y)
  doc.setFont("helvetica", "normal").setTextColor(...MUTED).setFontSize(9)
  const fromLines = [
    `Company No. ${COMPANY.companyNo}`,
    BILLING.vatNumber ? `VAT No. ${BILLING.vatNumber}` : "",
    COMPANY.email,
    COMPANY.phone,
  ].filter(Boolean)
  fromLines.forEach((l, i) => doc.text(l, M, y + 16 + i * 13))

  // Right meta block
  doc.setTextColor(...INK).setFont("helvetica", "bold").setFontSize(9)
  doc.text("Date", W - M - 150, y, { align: "left" })
  doc.text(meta.kind === "INVOICE" ? "Bill to" : "Prepared for", W - M - 150, y + 30, { align: "left" })
  doc.setFont("helvetica", "normal").setTextColor(...MUTED)
  doc.text(meta.date, W - M, y, { align: "right" })
  doc.setTextColor(...INK).setFont("helvetica", "bold").setFontSize(10)
  doc.text(meta.client || "—", W - M, y + 30, { align: "right" })

  // Line items table
  autoTable(doc, {
    startY: y + 70,
    head: [["Description", "Division", "Net", "VAT (20%)", "Total"]],
    body: [[meta.description, DIVISION_LABELS[meta.division] ?? meta.division, money(meta.net), money(meta.vat), money(meta.total)]],
    theme: "grid",
    headStyles: { fillColor: GREEN, textColor: 255, fontStyle: "bold", fontSize: 9 },
    bodyStyles: { fontSize: 9, textColor: INK },
    columnStyles: { 2: { halign: "right" }, 3: { halign: "right" }, 4: { halign: "right" } },
    margin: { left: M, right: M },
  })

  // Totals
  // @ts-expect-error autotable augments doc with lastAutoTable
  let ty = (doc.lastAutoTable?.finalY ?? y + 120) + 24
  const rx = W - M
  const lx = W - M - 180
  const row = (label: string, val: string, bold = false) => {
    doc.setFont("helvetica", bold ? "bold" : "normal").setFontSize(bold ? 11 : 9)
    doc.setTextColor(...(bold ? INK : MUTED))
    doc.text(label, lx, ty)
    doc.setTextColor(...INK)
    doc.text(val, rx, ty, { align: "right" })
    ty += bold ? 20 : 15
  }
  row("Net", money(meta.net))
  row("VAT (20%)", money(meta.vat))
  row("Total", money(meta.total), true)

  // Footer: payment / validity
  ty += 18
  doc.setDrawColor(225, 230, 222)
  doc.line(M, ty, W - M, ty)
  ty += 18
  doc.setFont("helvetica", "bold").setFontSize(9).setTextColor(...INK)
  doc.text(meta.kind === "INVOICE" ? "Payment" : "Validity", M, ty)
  doc.setFont("helvetica", "normal").setTextColor(...MUTED).setFontSize(8.5)
  const footLines =
    meta.kind === "INVOICE"
      ? [
          meta.validityNote,
          BILLING.bankName ? `Bank: ${BILLING.bankName}` : "",
          BILLING.sortCode ? `Sort code: ${BILLING.sortCode}   Account: ${BILLING.accountNumber}` : "",
          BILLING.accountName ? `Account name: ${BILLING.accountName}` : "",
          BILLING.vatNumber ? "" : "Note: VAT shown is provisional until a VAT number is set.",
        ].filter(Boolean)
      : [meta.validityNote, "Acceptance of this quote creates an invoice on the agreed terms."]
  footLines.forEach((l, i) => doc.text(l, M, ty + 16 + i * 13))

  // Page footer
  const H = doc.internal.pageSize.getHeight()
  doc.setFontSize(8).setTextColor(...MUTED)
  doc.text(`${COMPANY.legalName} · ${COMPANY.email} · ${COMPANY.phone}`, W / 2, H - 24, { align: "center" })

  return doc
}

export function invoicePdf(inv: Invoice): void {
  const doc = build({
    kind: "INVOICE",
    number: inv.number,
    date: inv.created_at?.slice(0, 10) ?? "",
    client: inv.client,
    division: inv.division,
    description: `${DIVISION_LABELS[inv.division] ?? inv.division} services`,
    net: inv.net_pence,
    vat: inv.vat_pence,
    total: inv.total_pence,
    status: inv.status,
    validityNote: `Payable within ${BILLING.paymentTermsDays} days of the invoice date.`,
  })
  doc.save(`${inv.number}.pdf`)
}

export function quotePdf(q: Quote): void {
  const doc = build({
    kind: "QUOTE",
    number: q.number,
    date: q.created_at?.slice(0, 10) ?? "",
    client: q.client,
    division: q.division,
    description: q.title,
    net: q.net_pence,
    vat: q.vat_pence,
    total: q.total_pence,
    status: q.status,
    validityNote: "This quote is valid for 30 days from the date above.",
  })
  doc.save(`${q.number}.pdf`)
}
