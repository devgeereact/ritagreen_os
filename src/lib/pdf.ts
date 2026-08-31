import { jsPDF } from "jspdf"
import autoTable from "jspdf-autotable"
import { COMPANY, BILLING } from "@/lib/company"
import { DIVISION_LABELS } from "@/lib/format"
import type { Invoice, Quote, CompliancePack, Payslip } from "@/lib/types"

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
  const y = 124
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

// Audit-ready compliance pack for one worker — CQC / SIA ACS evidence in one PDF.
const AMBER: [number, number, number] = [180, 120, 20]
const RED: [number, number, number] = [180, 40, 40]

function statusColour(status: string): [number, number, number] {
  if (status === "expired") return RED
  if (status === "expiring") return AMBER
  if (status === "valid") return GREEN
  return MUTED
}

export function compliancePackPdf(pack: CompliancePack): void {
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
  doc.text("Compliance evidence pack", M, 66)
  doc.setFont("helvetica", "bold").setFontSize(16)
  doc.text("COMPLIANCE PACK", W - M, 48, { align: "right" })

  // Worker identity
  const y = 124
  doc.setTextColor(...INK).setFont("helvetica", "bold").setFontSize(13)
  doc.text(pack.worker.name, M, y)
  doc.setFont("helvetica", "normal").setTextColor(...MUTED).setFontSize(9)
  const idLines = [
    `${pack.worker.role} · ${DIVISION_LABELS[pack.worker.division] ?? pack.worker.division}`,
    `Status: ${pack.worker.status}`,
    `Right to work: ${pack.worker.right_to_work ? "Verified" : "NOT VERIFIED"}`,
    pack.worker.recheck_due ? `Re-check due: ${pack.worker.recheck_due}` : "",
    pack.worker.email ?? "",
    pack.worker.phone ?? "",
  ].filter(Boolean)
  idLines.forEach((l, i) => doc.text(l, M, y + 16 + i * 13))

  // Credentials table
  autoTable(doc, {
    startY: y + 16 + idLines.length * 13 + 12,
    head: [["Credential", "Reference", "Expiry", "Status"]],
    body:
      pack.credentials.length > 0
        ? pack.credentials.map((c) => [
            c.type,
            c.identifier || "—",
            c.expiry || "—",
            c.status === "expiring" && c.days !== null ? `expiring · ${c.days}d` : c.status,
          ])
        : [["No credentials on file", "", "", ""]],
    theme: "grid",
    headStyles: { fillColor: GREEN, textColor: 255, fontStyle: "bold", fontSize: 9 },
    bodyStyles: { fontSize: 9, textColor: INK },
    margin: { left: M, right: M },
    didParseCell: (data) => {
      if (data.section === "body" && data.column.index === 3) {
        const c = pack.credentials[data.row.index]
        if (c) data.cell.styles.textColor = statusColour(c.status)
      }
    },
  })

  // @ts-expect-error autotable augments doc with lastAutoTable
  const afterCreds: number = (doc.lastAutoTable?.finalY ?? y + 200) + 18

  // References table
  autoTable(doc, {
    startY: afterCreds,
    head: [["Referee", "Organisation", "Relationship", "Status / gap"]],
    body:
      pack.references.length > 0
        ? pack.references.map((r) => [
            r.referee_name,
            r.referee_org || "—",
            r.relationship || "—",
            r.gap_note ? `${r.status} — ${r.gap_note}` : r.status,
          ])
        : [["No references recorded", "", "", ""]],
    theme: "grid",
    headStyles: { fillColor: INK, textColor: 255, fontStyle: "bold", fontSize: 9 },
    bodyStyles: { fontSize: 9, textColor: INK },
    margin: { left: M, right: M },
  })

  // @ts-expect-error autotable augments doc with lastAutoTable
  const afterRefs: number = (doc.lastAutoTable?.finalY ?? y + 300) + 18

  // Documents table
  autoTable(doc, {
    startY: afterRefs,
    head: [["Document on file", "Category", "Uploaded"]],
    body:
      pack.documents.length > 0
        ? pack.documents.map((d) => [d.original_name, d.category, d.created_at?.slice(0, 10) ?? ""])
        : [["No documents on file", "", ""]],
    theme: "grid",
    headStyles: { fillColor: INK, textColor: 255, fontStyle: "bold", fontSize: 9 },
    bodyStyles: { fontSize: 9, textColor: INK },
    margin: { left: M, right: M },
  })

  // Footer: provenance
  const H = doc.internal.pageSize.getHeight()
  doc.setFontSize(8).setTextColor(...MUTED)
  doc.text(
    `Generated ${pack.generated_at} by ${pack.generated_by} · ${COMPANY.legalName}`,
    W / 2,
    H - 24,
    { align: "center" },
  )

  doc.save(`compliance-pack-${pack.worker.name.replace(/\s+/g, "-").toLowerCase()}.pdf`)
}

// Worker payslip for one pay period.
export function payslipPdf(slip: Payslip): void {
  const doc = new jsPDF({ unit: "pt", format: "a4" })
  const W = doc.internal.pageSize.getWidth()
  const M = 40

  doc.setFillColor(...GREEN)
  doc.rect(0, 0, W, 90, "F")
  doc.setTextColor(255, 255, 255)
  doc.setFont("helvetica", "bold").setFontSize(24)
  doc.text("RitaGreen", M, 48)
  doc.setFont("helvetica", "normal").setFontSize(9)
  doc.text("Payslip", M, 66)
  doc.setFont("helvetica", "bold").setFontSize(16)
  doc.text("PAYSLIP", W - M, 48, { align: "right" })
  doc.setFont("helvetica", "normal").setFontSize(10)
  doc.text(`${slip.period.from} → ${slip.period.to}`, W - M, 66, { align: "right" })

  const y = 124
  doc.setTextColor(...INK).setFont("helvetica", "bold").setFontSize(13)
  doc.text(slip.worker.name, M, y)
  doc.setFont("helvetica", "normal").setTextColor(...MUTED).setFontSize(9)
  const idLines = [
    `${slip.worker.role} · ${DIVISION_LABELS[slip.worker.division] ?? slip.worker.division}`,
    `Pay type: ${slip.worker.pay_type.toUpperCase()}`,
    `Base rate: ${money(slip.worker.pay_rate_pence)}/hr`,
  ]
  idLines.forEach((l, i) => doc.text(l, M, y + 16 + i * 13))

  autoTable(doc, {
    startY: y + 16 + idLines.length * 13 + 12,
    head: [["Date", "Time", "Hours", "Premium", "OT hrs", "Gross"]],
    body:
      slip.lines.length > 0
        ? slip.lines.map((l) => [
            l.work_date,
            `${l.start_time}–${l.end_time}`,
            l.hours.toFixed(2),
            l.premium,
            l.overtime_hours ? l.overtime_hours.toFixed(2) : "—",
            money(l.gross_pence),
          ])
        : [["No approved hours in this period", "", "", "", "", ""]],
    theme: "grid",
    headStyles: { fillColor: GREEN, textColor: 255, fontStyle: "bold", fontSize: 9 },
    bodyStyles: { fontSize: 8.5, textColor: INK },
    columnStyles: { 2: { halign: "right" }, 4: { halign: "right" }, 5: { halign: "right" } },
    margin: { left: M, right: M },
  })

  // @ts-expect-error autotable augments doc with lastAutoTable
  let ty: number = (doc.lastAutoTable?.finalY ?? y + 200) + 24
  const rx = W - M
  const lx = W - M - 200
  const row = (label: string, val: string, bold = false) => {
    doc.setFont("helvetica", bold ? "bold" : "normal").setFontSize(bold ? 11 : 9)
    doc.setTextColor(...(bold ? INK : MUTED))
    doc.text(label, lx, ty)
    doc.setTextColor(...INK)
    doc.text(val, rx, ty, { align: "right" })
    ty += bold ? 20 : 15
  }
  row("Total hours", slip.totals.hours.toFixed(2))
  row("Base pay", money(slip.totals.base_pence))
  row("Premiums (night/weekend/BH/OT)", money(slip.totals.premium_pence))
  row("Gross pay", money(slip.totals.gross_pence), true)
  row(`Holiday accrued (${slip.totals.holiday_hours.toFixed(2)} hrs)`, money(slip.totals.holiday_pay_pence))

  const H = doc.internal.pageSize.getHeight()
  doc.setFontSize(8).setTextColor(...MUTED)
  doc.text(
    `${COMPANY.legalName} · This payslip is provisional until confirmed in the pay run.`,
    W / 2,
    H - 24,
    { align: "center" },
  )

  doc.save(`payslip-${slip.worker.name.replace(/\s+/g, "-").toLowerCase()}-${slip.period.from}.pdf`)
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
