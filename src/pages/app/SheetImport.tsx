import { useMemo, useRef, useState } from "react"
import { Link, useNavigate, useParams } from "react-router-dom"
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  CircleHelp,
  Copy,
  Download,
  FileSpreadsheet,
  MinusCircle,
  RefreshCw,
  Upload,
  UserPlus,
  XCircle,
} from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
import { api, ApiError, downloadFile } from "@/lib/api"
import { shortDate } from "@/lib/format"
import { personPath } from "@/lib/people"
import { SERVICE_OPTIONS, serviceLabel } from "@/lib/services"
import { cn } from "@/lib/utils"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"
import type { ImportMapping, ImportRow, SheetImportHistory, SheetImportView } from "@/lib/types"

// Recruitment > Import from spreadsheet (section S8, docs/Plans 2026/06).
//
// Upload a CSV, map its columns, preview what every row will do, decide the
// uncertain matches, tick the rows to apply, and read the report. The server
// decides every outcome (server/sheet-import.php and pure.php); this screen
// only shows it and sends back which rows were ticked and, for an uncertain
// match, which person it is. It never sends values to write.

const MAX_BYTES = 2_000_000
const NONE = "none"

const OUTCOME: Record<string, { label: string; className: string; icon: typeof CheckCircle2 }> = {
  new: { label: "New applicant", className: "bg-success-soft text-success", icon: UserPlus },
  update: { label: "Update existing person", className: "bg-success-soft text-success", icon: RefreshCw },
  duplicate: { label: "Already received", className: "bg-muted text-muted-foreground", icon: Copy },
  no_change: { label: "No change", className: "bg-muted text-muted-foreground", icon: MinusCircle },
  uncertain: { label: "Needs a decision", className: "bg-warning-soft text-warning", icon: CircleHelp },
  problem: { label: "Problem", className: "bg-destructive-soft text-destructive", icon: AlertTriangle },
  created: { label: "Created", className: "bg-success-soft text-success", icon: UserPlus },
  updated: { label: "Updated", className: "bg-success-soft text-success", icon: RefreshCw },
  skipped: { label: "Skipped", className: "bg-muted text-muted-foreground", icon: MinusCircle },
  failed: { label: "Failed", className: "bg-destructive-soft text-destructive", icon: XCircle },
  pending: { label: "Not applied", className: "bg-muted text-muted-foreground", icon: MinusCircle },
}

const PREVIEW_ORDER = ["new", "update", "uncertain", "duplicate", "no_change", "problem"] as const

function OutcomeBadge({ outcome }: { outcome: string }) {
  const o = OUTCOME[outcome] ?? OUTCOME.pending
  const Icon = o.icon
  return (
    <span className={cn("inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium", o.className)}>
      <Icon className="size-3.5" aria-hidden />
      {o.label}
    </span>
  )
}

function normalise(h: string): string {
  return h.replace(/\uFEFF/g, "").replace(/\u00A0/g, " ").replace(/\s+/g, " ").trim().toLowerCase().replace(/[\s*:]+$/, "").trim()
}

export default function SheetImport() {
  const { id } = useParams()
  return (
    <div className="space-y-6">
      <div>
        <Link to="/app/ats" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" /> Recruitment
        </Link>
        <h1 className="mt-1 text-2xl font-bold tracking-tight">Import from spreadsheet</h1>
        <p className="text-sm text-muted-foreground">
          Bring in form responses already in a Google Sheet, or update staff contact details in bulk. Nothing is
          changed until you tick the rows and apply them.
        </p>
      </div>
      {id ? <ImportDetail id={Number(id)} /> : <UploadAndHistory />}
    </div>
  )
}

// ---- Upload and history --------------------------------------------------------------------

function UploadAndHistory() {
  const navigate = useNavigate()
  const history = useApi<{ imports: SheetImportHistory[] }>("sheet-import.php?action=history")
  const [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  async function upload(file: File) {
    if (/\.(xlsx|xls|ods|numbers)$/i.test(file.name)) {
      toast.error("That is a spreadsheet file. In Google Sheets choose File, Download, Comma-separated values (.csv), then upload that.")
      return
    }
    if (file.size > MAX_BYTES) {
      toast.error("That file is larger than 2 MB. Split it into smaller files.")
      return
    }
    setBusy(true)
    try {
      const csv = await file.text()
      const res = await api<{ id: number; rows: number }>("sheet-import.php?action=upload", {
        method: "POST",
        body: { file_name: file.name, csv },
      })
      toast.success(`${res.rows} rows read. Check the columns next.`)
      navigate(`/app/ats/import/${res.id}`)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not read that file.")
    } finally {
      setBusy(false)
      if (input.current) input.current.value = ""
    }
  }

  const imports = history.data?.imports ?? []

  return (
    <>
      <Card className="p-5">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
          <span className="grid size-11 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
            <FileSpreadsheet className="size-6" aria-hidden />
          </span>
          <div className="min-w-0 flex-1 space-y-2">
            <h2 className="font-semibold">Upload a CSV file</h2>
            <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
              <li>Open the responses Sheet in Google Sheets.</li>
              <li>
                Choose <strong className="text-foreground">File, Download, Comma-separated values (.csv)</strong>.
                XLSX files are not read: download as CSV instead.
              </li>
              <li>Upload the file here. The first row must be the column headings.</li>
            </ol>
            <p className="text-xs text-muted-foreground">
              Up to 1,000 rows and 2 MB. The file itself is not kept: its rows are held only until you apply or close
              the import, and a failed row only until the import is closed.
            </p>
            <input
              ref={input}
              type="file"
              accept=".csv,text/csv"
              className="sr-only"
              id="sheet-file"
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) void upload(f)
              }}
            />
            <Button onClick={() => input.current?.click()} disabled={busy}>
              <Upload className="mr-1 size-4" /> {busy ? "Reading the file..." : "Choose a CSV file"}
            </Button>
          </div>
        </div>
      </Card>

      <div className="space-y-3">
        <h2 className="font-semibold">Import history</h2>
        {history.loading && <LoadingBlock rows={3} />}
        {history.error && <ErrorBlock message={history.error} onRetry={history.reload} />}
        {history.data && imports.length === 0 && (
          <EmptyBlock title="No imports yet" hint="Each file you upload is listed here with what it created and changed." />
        )}
        {imports.length > 0 && (
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>File</TableHead>
                  <TableHead className="hidden sm:table-cell">Uploaded</TableHead>
                  <TableHead className="text-right">Created</TableHead>
                  <TableHead className="text-right">Updated</TableHead>
                  <TableHead className="hidden text-right sm:table-cell">Skipped</TableHead>
                  <TableHead className="text-right">Failed</TableHead>
                  <TableHead className="hidden sm:table-cell">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {imports.map((i) => (
                  <TableRow key={i.id}>
                    <TableCell className="max-w-[12rem] sm:max-w-xs">
                      <Link to={`/app/ats/import/${i.id}`} className="block truncate font-medium hover:underline">
                        {i.file_name}
                      </Link>
                      <span className="text-xs text-muted-foreground">
                        {i.row_count} rows{i.created_by_name ? `, ${i.created_by_name}` : ""}
                      </span>
                    </TableCell>
                    <TableCell className="hidden text-muted-foreground sm:table-cell">{shortDate(i.created_at)}</TableCell>
                    <TableCell className="text-right tabular-nums">{i.created_count}</TableCell>
                    <TableCell className="text-right tabular-nums">{i.updated_count}</TableCell>
                    <TableCell className="hidden text-right tabular-nums sm:table-cell">{i.skipped_count}</TableCell>
                    <TableCell className="text-right tabular-nums">{i.failed_count}</TableCell>
                    <TableCell className="hidden sm:table-cell">
                      <Badge variant="outline">{i.status === "open" ? "Not applied" : i.status === "applied" ? "Applied" : "Closed"}</Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}
      </div>
    </>
  )
}

// ---- One import ----------------------------------------------------------------------------

type Step = "map" | "preview" | "report"

function ImportDetail({ id }: { id: number }) {
  const loaded = useApi<SheetImportView>(`sheet-import.php?id=${id}`, [id])
  const meta = useApi<{ mappings: ImportMapping[]; forms: { id: number; title: string }[]; fields: { key: string; label: string }[] }>(
    "sheet-import.php?action=mappings",
  )
  // What the server last returned: the load, or a later map/apply/close answer.
  const [latest, setView] = useState<SheetImportView | null>(null)
  const view = latest ?? loaded.data
  const [chosenStep, setStep] = useState<Step | null>(null)
  const step: Step = chosenStep ?? (view?.import.status === "open" ? "map" : "report")

  if (loaded.loading || meta.loading) return <LoadingBlock rows={6} />
  if (loaded.error) return <ErrorBlock message={loaded.error} onRetry={loaded.reload} />
  if (meta.error) return <ErrorBlock message={meta.error} onRetry={meta.reload} />
  if (!view || !meta.data) return null

  const open = view.import.status === "open"
  const steps: { key: Step; label: string }[] = [
    { key: "map", label: "1. Columns" },
    { key: "preview", label: "2. Preview" },
    { key: "report", label: "3. Report" },
  ]

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span className="font-medium">{view.import.file_name}</span>
        <span className="text-muted-foreground">{view.import.row_count} rows</span>
      </div>
      <nav aria-label="Import steps" className="flex gap-1 overflow-x-auto rounded-lg bg-muted p-1 text-sm">
        {steps.map((s) => {
          const enabled = open ? s.key !== "report" : s.key === "report"
          return (
            <button
              key={s.key}
              type="button"
              disabled={!enabled}
              aria-current={step === s.key ? "step" : undefined}
              onClick={() => setStep(s.key)}
              className={cn(
                "flex-1 whitespace-nowrap rounded-md px-3 py-1.5 font-medium transition-colors disabled:opacity-50",
                step === s.key ? "bg-background shadow-sm" : "text-muted-foreground hover:text-foreground",
              )}
            >
              {s.label}
            </button>
          )
        })}
      </nav>

      {step === "map" && open && (
        <MapStep
          view={view}
          mappings={meta.data.mappings}
          forms={meta.data.forms}
          fields={meta.data.fields}
          onMapped={(v) => {
            setView(v)
            meta.reload()
            setStep("preview")
          }}
        />
      )}
      {step === "preview" && open && (
        <PreviewStep
          view={view}
          onApplied={(v) => {
            setView(v)
            setStep("report")
          }}
        />
      )}
      {step === "report" && <ReportStep view={view} onChanged={setView} reload={loaded.reload} />}
    </div>
  )
}

// ---- Step 1: columns -----------------------------------------------------------------------

function MapStep({
  view,
  mappings,
  forms,
  fields,
  onMapped,
}: {
  view: SheetImportView
  mappings: ImportMapping[]
  forms: { id: number; title: string }[]
  fields: { key: string; label: string }[]
  onMapped: (v: SheetImportView) => void
}) {
  const current = mappings.find((m) => m.id === view.import.mapping_id) ?? null
  const [columns, setColumns] = useState<Record<string, string>>(() => {
    const out: Record<string, string> = {}
    for (const h of view.headers) out[h] = view.columns[normalise(h)] ?? "ignore"
    return out
  })
  const [purpose, setPurpose] = useState(view.import.purpose)
  const [service, setService] = useState(view.import.default_service ?? NONE)
  const [save, setSave] = useState(true)
  const [name, setName] = useState(current?.name ?? view.import.file_name.replace(/\.csv$/i, "").replace(/\s*\(\d+\)$/, ""))
  const [formId, setFormId] = useState(current?.website_form_id ? String(current.website_form_id) : NONE)
  const [busy, setBusy] = useState(false)

  async function submit() {
    setBusy(true)
    try {
      const v = await api<SheetImportView>(`sheet-import.php?action=map&id=${view.import.id}`, {
        method: "POST",
        body: {
          columns,
          purpose,
          default_service: service === NONE ? null : service,
          save: save
            ? { name, website_form_id: formId === NONE ? null : Number(formId), mapping_id: current?.id ?? null }
            : null,
        },
      })
      onMapped(v)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the columns.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-5">
      {current && (
        <p className="rounded-lg bg-success-soft px-3 py-2 text-sm text-success">
          <CheckCircle2 className="mr-1 inline size-4" aria-hidden /> Using the saved mapping "{current.name}"
          {current.form_title ? ` for ${current.form_title}` : ""}. Check it still fits.
        </p>
      )}
      <Card className="grid gap-4 p-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="purpose">What is in this file?</Label>
          <Select value={purpose} onValueChange={(v) => setPurpose(v as typeof purpose)}>
            <SelectTrigger id="purpose"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="applications">Applications</SelectItem>
              <SelectItem value="staff_updates">Staff contact details</SelectItem>
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {purpose === "applications"
              ? "New applicants are added; anyone already on staff has their contact details updated."
              : "Only people already on staff are changed. Nobody new is added."}
          </p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="service">Service when a row does not say</Label>
          <Select value={service} onValueChange={setService}>
            <SelectTrigger id="service"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value={NONE}>No default</SelectItem>
              {SERVICE_OPTIONS.map((s) => (
                <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </Card>

      <Card className="divide-y">
        <div className="hidden grid-cols-[1fr_1fr_15rem] gap-4 px-4 py-2 text-xs font-medium text-muted-foreground sm:grid">
          <span>Column in the file</span>
          <span>First answers</span>
          <span>Goes into</span>
        </div>
        {view.headers.map((h) => (
          <div key={h} className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_1fr_15rem] sm:items-center sm:gap-4">
            <span className="font-medium break-words">{h}</span>
            <span className="truncate text-sm text-muted-foreground">
              {(view.samples[h] ?? []).join(" · ") || "Blank"}
            </span>
            <Select value={columns[h] ?? "ignore"} onValueChange={(v) => setColumns((c) => ({ ...c, [h]: v }))}>
              <SelectTrigger aria-label={`Field for ${h}`}><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="ignore">Do not import</SelectItem>
                {fields.map((f) => (
                  <SelectItem key={f.key} value={f.key}>{f.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        ))}
      </Card>
      <p className="text-xs text-muted-foreground">
        Only these fields can be imported. Compliance, checks, pay and employment status are never changed by an import.
      </p>

      <Card className="space-y-3 p-4">
        <label className="flex items-center gap-2 text-sm font-medium">
          <Checkbox checked={save} onCheckedChange={(v) => setSave(v === true)} /> Save these columns for next time
        </label>
        {save && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="map-name">Name</Label>
              <Input id="map-name" value={name} maxLength={160} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="map-form">Form it belongs to</Label>
              <Select value={formId} onValueChange={setFormId}>
                <SelectTrigger id="map-form"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>Not linked to a form</SelectItem>
                  {forms.map((f) => (
                    <SelectItem key={f.id} value={String(f.id)}>{f.title}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        )}
      </Card>

      <div className="flex justify-end">
        <Button onClick={submit} disabled={busy || (save && name.trim() === "")}>
          {busy ? "Checking every row..." : "Preview the rows"}
        </Button>
      </div>
    </div>
  )
}

// ---- Step 2: preview -----------------------------------------------------------------------

function defaultTicked(r: ImportRow): boolean {
  return r.outcome === "new" || r.outcome === "update"
}

function PreviewStep({ view, onApplied }: { view: SheetImportView; onApplied: (v: SheetImportView) => void }) {
  const [rows, setRows] = useState<ImportRow[]>(view.rows)
  const [ticked, setTicked] = useState<Record<number, boolean>>(() =>
    Object.fromEntries(view.rows.map((r) => [r.id, defaultTicked(r)])),
  )
  const [choices, setChoices] = useState<Record<number, string>>({})
  const [filter, setFilter] = useState<string>("all")
  const [confirm, setConfirm] = useState(false)
  const [busy, setBusy] = useState(false)

  const original = useMemo(() => Object.fromEntries(view.rows.map((r) => [r.id, r])), [view.rows])
  const tally = useMemo(() => {
    const t: Record<string, number> = {}
    for (const r of view.rows) t[r.outcome] = (t[r.outcome] ?? 0) + 1
    return t
  }, [view.rows])
  const selected = rows.filter((r) => ticked[r.id])
  const shown = filter === "all" ? rows : rows.filter((r) => original[r.id]?.outcome === filter)

  async function choose(row: ImportRow, choice: string) {
    setChoices((c) => ({ ...c, [row.id]: choice }))
    try {
      const res = await api<{ row: ImportRow }>(
        `sheet-import.php?action=row&id=${view.import.id}&row=${row.id}&choice=${encodeURIComponent(choice)}`,
      )
      // Keep the candidates from the first preview so the choice can be changed.
      setRows((rs) => rs.map((r) => (r.id === row.id ? { ...res.row, candidates: original[row.id]?.candidates } : r)))
      setTicked((t) => ({ ...t, [row.id]: res.row.outcome === "new" || res.row.outcome === "update" }))
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not check that choice.")
    }
  }

  async function apply() {
    setBusy(true)
    try {
      const body = {
        rows: selected.map((r) => (choices[r.id] ? { id: r.id, choice: choices[r.id] } : { id: r.id })),
      }
      const v = await api<SheetImportView>(`sheet-import.php?action=apply&id=${view.import.id}`, { method: "POST", body })
      toast.success("Import applied.")
      setConfirm(false)
      onApplied(v)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not apply the import.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Show rows">
        <FilterChip active={filter === "all"} onClick={() => setFilter("all")} label={`All ${view.rows.length}`} />
        {PREVIEW_ORDER.filter((o) => tally[o]).map((o) => (
          <FilterChip key={o} active={filter === o} onClick={() => setFilter(o)} label={`${OUTCOME[o].label} ${tally[o]}`} />
        ))}
      </div>

      <div className="space-y-3">
        {shown.map((r) => (
          <PreviewRow
            key={r.id}
            row={r}
            original={original[r.id]}
            ticked={!!ticked[r.id]}
            choice={choices[r.id] ?? null}
            onTick={(v) => setTicked((t) => ({ ...t, [r.id]: v }))}
            onChoose={(c) => void choose(r, c)}
          />
        ))}
      </div>

      <Card className="sticky bottom-3 z-10 flex flex-col gap-3 p-4 shadow-md sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          <strong className="text-foreground">{selected.length}</strong> of {rows.length} rows ticked. Rows left unticked are
          recorded as skipped.
        </p>
        <Button onClick={() => setConfirm(true)} disabled={selected.length === 0}>
          Apply {selected.length} {selected.length === 1 ? "row" : "rows"}
        </Button>
      </Card>

      <Dialog open={confirm} onOpenChange={setConfirm}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Apply {selected.length} rows?</DialogTitle>
            <DialogDescription>
              New applicants are added to Recruitment at Applied, and the contact details shown are changed. Every change is
              recorded with the file name and row. An import cannot be applied twice.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirm(false)}>Cancel</Button>
            <Button onClick={apply} disabled={busy}>{busy ? "Applying..." : "Apply"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function FilterChip({ active, onClick, label }: { active: boolean; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "rounded-full border px-3 py-1 text-sm transition-colors",
        active ? "border-primary bg-primary text-primary-foreground" : "bg-background hover:bg-muted",
      )}
    >
      {label}
    </button>
  )
}

function PreviewRow({
  row,
  original,
  ticked,
  choice,
  onTick,
  onChoose,
}: {
  row: ImportRow
  original: ImportRow | undefined
  ticked: boolean
  choice: string | null
  onTick: (v: boolean) => void
  onChoose: (c: string) => void
}) {
  const applicable = row.outcome === "new" || row.outcome === "update"
  const candidates = original?.candidates ?? []
  const wasUncertain = original?.outcome === "uncertain"
  const checkboxId = `row-${row.id}`

  return (
    <Card className={cn("p-4", ticked && "ring-1 ring-primary/40")}>
      <div className="flex items-start gap-3">
        <Checkbox
          id={checkboxId}
          className="mt-1"
          checked={ticked}
          disabled={!applicable}
          onCheckedChange={(v) => onTick(v === true)}
          aria-label={`Apply row ${row.row}`}
        />
        <div className="min-w-0 flex-1 space-y-2">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <label htmlFor={checkboxId} className="font-medium">
              <span className="mr-2 text-xs font-normal text-muted-foreground">Row {row.row}</span>
              {row.name || "No name"}
            </label>
            <OutcomeBadge outcome={row.outcome} />
          </div>
          <p className="break-words text-sm text-muted-foreground">
            {[row.role, row.service ? serviceLabel(row.service) : null, row.email, row.phone].filter(Boolean).join(" · ")}
          </p>
          {row.person && (
            <p className="text-sm">
              Matches{" "}
              <Link to={personPath(row.person.id)} className="font-medium underline-offset-2 hover:underline">
                {row.person.name}
              </Link>
              {row.person.staff ? " (staff)" : " (applicant)"}
            </p>
          )}
          {row.reason && <p className="text-sm">{row.reason}</p>}

          {row.changes && row.changes.length > 0 && (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                  <tr>
                    <th className="px-3 py-1.5 font-medium">Field</th>
                    <th className="px-3 py-1.5 font-medium">Now</th>
                    <th className="px-3 py-1.5 font-medium">From the sheet</th>
                  </tr>
                </thead>
                <tbody>
                  {row.changes.map((c) => (
                    <tr key={c.field} className="border-t">
                      <td className="px-3 py-1.5">{c.label}</td>
                      <td className="px-3 py-1.5 text-muted-foreground line-through decoration-muted-foreground/50">
                        {c.old ?? "Not recorded"}
                      </td>
                      <td className="px-3 py-1.5 font-medium">{c.new}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {wasUncertain && candidates.length > 0 && (
            <fieldset className="space-y-2 rounded-md border border-warning/40 bg-warning-soft/40 p-3">
              <legend className="px-1 text-sm font-medium">Who is this?</legend>
              <RadioGroup value={choice ?? ""} onValueChange={onChoose} className="gap-2">
                {candidates.map((c) => (
                  <label key={c.id} className="flex cursor-pointer items-start gap-2 text-sm">
                    <RadioGroupItem value={String(c.id)} className="mt-0.5" />
                    <span>
                      <span className="font-medium">{c.name}</span>
                      {c.staff ? " (staff)" : " (applicant)"}
                      <span className="block text-muted-foreground">
                        {[c.email, c.phone, c.services.map(serviceLabel).join(", ")].filter(Boolean).join(" · ")}
                      </span>
                    </span>
                  </label>
                ))}
                <label className="flex cursor-pointer items-start gap-2 text-sm">
                  <RadioGroupItem value="new" className="mt-0.5" />
                  <span className="font-medium">Someone new: create a separate person</span>
                </label>
              </RadioGroup>
            </fieldset>
          )}
        </div>
      </div>
    </Card>
  )
}

// ---- Step 3: report ------------------------------------------------------------------------

function ReportStep({
  view,
  onChanged,
  reload,
}: {
  view: SheetImportView
  onChanged: (v: SheetImportView) => void
  reload: () => void
}) {
  const [closing, setClosing] = useState(false)
  const c = view.import.counts
  const tiles = [
    { label: "Created", value: c.created, className: "text-success" },
    { label: "Updated", value: c.updated, className: "text-success" },
    { label: "Skipped", value: c.skipped, className: "text-muted-foreground" },
    { label: "Failed", value: c.failed, className: c.failed ? "text-destructive" : "text-muted-foreground" },
  ]

  async function close() {
    setClosing(true)
    try {
      await api(`sheet-import.php?action=close&id=${view.import.id}`, { method: "POST", body: {} })
      toast.success("Import closed. The held rows have been cleared.")
      onChanged({ ...view, import: { ...view.import, status: "closed" } })
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not close the import.")
    } finally {
      setClosing(false)
    }
  }

  async function failures() {
    try {
      const base = view.import.file_name.replace(/\.csv$/i, "")
      await downloadFile(`sheet-import.php?action=failures&id=${view.import.id}`, `${base} failures.csv`)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not download the failures.")
    }
  }

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {tiles.map((t) => (
          <Card key={t.label} className="p-4">
            <p className="text-sm text-muted-foreground">{t.label}</p>
            <p className={cn("text-3xl font-semibold tabular-nums", t.className)}>{t.value}</p>
          </Card>
        ))}
      </div>
      <p className="text-sm text-muted-foreground">
        {view.import.applied_at ? `Applied ${shortDate(view.import.applied_at)}. ` : ""}
        Every change is in the audit log with the file name and row number.
        {view.import.status === "closed" ? " This import is closed and its rows have been cleared." : ""}
      </p>
      <div className="flex flex-wrap gap-2">
        {c.failed > 0 && view.import.status === "applied" && (
          <Button variant="outline" onClick={failures}>
            <Download className="mr-1 size-4" /> Download failed rows (CSV)
          </Button>
        )}
        <Button variant="outline" asChild>
          <Link to="/app/ats">Open Recruitment</Link>
        </Button>
        {view.import.status !== "closed" && (
          <Button variant="ghost" onClick={close} disabled={closing}>
            {closing ? "Closing..." : "Close import and clear its rows"}
          </Button>
        )}
      </div>

      <Card className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-16">Row</TableHead>
              <TableHead>Result</TableHead>
              <TableHead>Detail</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {view.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="tabular-nums">{r.row}</TableCell>
                <TableCell><OutcomeBadge outcome={r.outcome} /></TableCell>
                <TableCell className="text-sm">
                  {r.reason ??
                    (r.outcome === "updated" ? `Changed: ${(r.changed_fields ?? []).join(", ").replace(/_/g, " ")}` : "")}
                  {r.person_id && (r.outcome === "created" || r.outcome === "updated") && (
                    <Link to={personPath(r.person_id)} className="ml-2 underline-offset-2 hover:underline">
                      Open person
                    </Link>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
    </div>
  )
}
