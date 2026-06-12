import { useRef, useState } from "react"
import { Upload, Download, Trash2, FileText, Sparkles, Loader2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { uploadFile, downloadFile, api, ApiError } from "@/lib/api"
import type { FileDoc } from "@/lib/types"
import { shortDate } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

// File types ai.php / files.php can read as text for AI summarising.
const SUMMARISABLE = new Set([
  "application/pdf",
  "text/plain",
  "text/csv",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
])

const CATEGORIES = [
  { value: "contract", label: "Contract" },
  { value: "certificate", label: "Certificate" },
  { value: "policy", label: "Policy" },
  { value: "rams", label: "RAMS / risk" },
  { value: "id", label: "ID / right to work" },
  { value: "other", label: "Other" },
]

function humanSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export default function Files() {
  const { data, loading, error, reload } = useApi<{ files: FileDoc[] }>("files.php")
  const [category, setCategory] = useState("other")
  const [uploading, setUploading] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  async function onPick(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    const form = new FormData()
    form.append("file", file)
    form.append("category", category)
    setUploading(true)
    try {
      await uploadFile("files.php", form)
      toast.success("File uploaded.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Upload failed.")
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ""
    }
  }

  async function download(f: FileDoc) {
    try {
      await downloadFile(`files.php?action=download&id=${f.id}`, f.original_name)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Download failed.")
    }
  }

  const [summary, setSummary] = useState<{ open: boolean; name: string; loading: boolean; text: string }>(
    { open: false, name: "", loading: false, text: "" },
  )

  async function summarise(f: FileDoc) {
    setSummary({ open: true, name: f.original_name, loading: true, text: "" })
    try {
      const doc = await api<{ text: string; note: string | null; truncated: boolean }>(
        `files.php?action=extract&id=${f.id}`,
      )
      if (!doc.text || doc.text.trim() === "") {
        setSummary((s) => ({ ...s, loading: false, text: doc.note || "No readable text was found in this file." }))
        return
      }
      const res = await api<{ reply: string }>("ai.php", {
        method: "POST",
        body: {
          system:
            "You summarise business documents for RitaGreen care, training, cleaning and security staff. " +
            "Give a tight summary: 2-3 sentences, then key points as bullets. Flag any dates, obligations, " +
            "names or amounts. UK English. If it's a contract or certificate, note parties and expiry.",
          prompt: `Summarise this document (${f.original_name}):\n\n${doc.text}`,
        },
      })
      const tail = doc.truncated ? "\n\n_(Long document — summary based on the first part.)_" : ""
      setSummary((s) => ({ ...s, loading: false, text: res.reply + tail }))
    } catch (err) {
      setSummary({ open: false, name: "", loading: false, text: "" })
      toast.error(err instanceof ApiError ? err.message : "Could not summarise this file.")
    }
  }

  async function remove(id: number) {
    try {
      await api(`files.php?action=delete&id=${id}`, { method: "POST", body: {} })
      toast.success("File removed.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not remove the file.")
    }
  }

  const files = data?.files ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Files & documents</h1>
          <p className="text-sm text-muted-foreground">
            One secure store. PDFs, images and Office docs up to 10 MB.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={category} onValueChange={setCategory}>
            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              {CATEGORIES.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}
            </SelectContent>
          </Select>
          <input ref={inputRef} type="file" className="hidden" onChange={onPick} />
          <Button onClick={() => inputRef.current?.click()} disabled={uploading}>
            <Upload className="mr-1 size-4" /> {uploading ? "Uploading…" : "Upload"}
          </Button>
        </div>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && files.length === 0 && (
        <EmptyBlock
          title="No files yet"
          hint="Upload contracts, certificates, policies and RAMS. Choose a category, then Upload."
        />
      )}

      {data && !loading && files.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>File</TableHead>
                <TableHead>Category</TableHead>
                <TableHead>Size</TableHead>
                <TableHead>Uploaded by</TableHead>
                <TableHead>When</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {files.map((f) => (
                <TableRow key={f.id}>
                  <TableCell>
                    <span className="inline-flex items-center gap-2 font-medium">
                      <FileText className="size-4 text-muted-foreground" /> {f.original_name}
                    </span>
                  </TableCell>
                  <TableCell><Badge variant="secondary" className="capitalize">{f.category}</Badge></TableCell>
                  <TableCell className="text-muted-foreground">{humanSize(f.size_bytes)}</TableCell>
                  <TableCell className="text-muted-foreground">{f.uploaded_by || "—"}</TableCell>
                  <TableCell className="text-muted-foreground">{shortDate(f.created_at)}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      {SUMMARISABLE.has(f.mime) && (
                        <Button size="icon" variant="ghost" onClick={() => summarise(f)} aria-label="Summarise with AI">
                          <Sparkles className="size-4 text-primary" />
                        </Button>
                      )}
                      <Button size="icon" variant="ghost" onClick={() => download(f)} aria-label="Download">
                        <Download className="size-4" />
                      </Button>
                      <Button size="icon" variant="ghost" onClick={() => remove(f.id)} aria-label="Delete">
                        <Trash2 className="size-4 text-destructive" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Dialog open={summary.open} onOpenChange={(o) => setSummary((s) => ({ ...s, open: o }))}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Sparkles className="size-4 text-primary" /> AI summary
            </DialogTitle>
            <DialogDescription className="truncate">{summary.name}</DialogDescription>
          </DialogHeader>
          {summary.loading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" /> Reading and summarising…
            </div>
          ) : (
            <div className="max-h-[60vh] overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-foreground">
              {summary.text}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
