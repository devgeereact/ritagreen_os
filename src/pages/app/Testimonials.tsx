import { useState } from "react"
import { Plus, Pencil, Trash2, Star } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { cn } from "@/lib/utils"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Item = {
  id: number
  author: string
  role: string
  rating: number
  body: string
  status: "draft" | "published"
  sort_order: number
}

const EMPTY: Item = {
  id: 0,
  author: "",
  role: "",
  rating: 5,
  body: "",
  status: "published",
  sort_order: 0,
}

function Stars({ rating }: { rating: number }) {
  return (
    <span className="inline-flex">
      {[1, 2, 3, 4, 5].map((i) => (
        <Star
          key={i}
          className={cn("size-3.5", i <= rating ? "fill-amber-400 text-amber-400" : "fill-muted text-muted")}
        />
      ))}
    </span>
  )
}

export default function Testimonials() {
  const { data, loading, error, reload } = useApi<{ testimonials: Item[] }>("testimonials.php?action=all")
  const [draft, setDraft] = useState<Item | null>(null)
  const [saving, setSaving] = useState(false)

  const items = data?.testimonials ?? []
  const isEdit = !!draft && draft.id > 0

  function set<K extends keyof Item>(key: K, value: Item[K]) {
    setDraft((d) => (d ? { ...d, [key]: value } : d))
  }

  async function save() {
    if (!draft) return
    if (!draft.author.trim() || !draft.body.trim()) {
      toast.error("Author and testimonial text are required.")
      return
    }
    setSaving(true)
    try {
      const qs = isEdit
        ? `testimonials.php?action=update&id=${draft.id}`
        : "testimonials.php?action=create"
      await api(qs, {
        method: "POST",
        body: {
          author: draft.author.trim(),
          role: draft.role.trim(),
          rating: draft.rating,
          body: draft.body.trim(),
          status: draft.status,
          sort_order: draft.sort_order || 0,
        },
      })
      toast.success(isEdit ? "Testimonial updated." : "Testimonial added.")
      setDraft(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the testimonial.")
    } finally {
      setSaving(false)
    }
  }

  async function remove(item: Item) {
    if (!confirm(`Delete the testimonial from "${item.author}"?`)) return
    try {
      await api(`testimonials.php?action=delete&id=${item.id}`, { method: "POST", body: {} })
      toast.success("Testimonial deleted.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not delete the testimonial.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Testimonials</h1>
          <p className="text-sm text-muted-foreground">
            Published testimonials appear on the public site. Lower sort order shows first.
          </p>
        </div>
        <Button onClick={() => setDraft({ ...EMPTY })}>
          <Plus className="mr-1 size-4" /> Add testimonial
        </Button>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && items.length === 0 && (
        <EmptyBlock title="No testimonials yet" hint="Add approved client quotes to show them on the website." />
      )}

      {items.length > 0 && (
        <div className="surface overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Author</TableHead>
                <TableHead>Rating</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Order</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((t) => (
                <TableRow key={t.id}>
                  <TableCell>
                    <div className="font-medium">{t.author}</div>
                    {t.role && <div className="text-xs text-muted-foreground">{t.role}</div>}
                  </TableCell>
                  <TableCell><Stars rating={t.rating} /></TableCell>
                  <TableCell>
                    <Badge variant={t.status === "published" ? "default" : "secondary"}>{t.status}</Badge>
                  </TableCell>
                  <TableCell className="tabular text-muted-foreground">{t.sort_order}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button variant="ghost" size="icon" onClick={() => setDraft({ ...t })} aria-label="Edit">
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => remove(t)}
                        aria-label="Delete"
                        className="text-destructive hover:text-destructive"
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <Dialog open={!!draft} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{isEdit ? "Edit testimonial" : "Add testimonial"}</DialogTitle>
            <DialogDescription>Only use real, approved client quotes.</DialogDescription>
          </DialogHeader>

          {draft && (
            <div className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="t-author">Author</Label>
                  <Input id="t-author" value={draft.author} onChange={(e) => set("author", e.target.value)} />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="t-role">Role / company</Label>
                  <Input
                    id="t-role"
                    value={draft.role}
                    placeholder="Care home manager, Lewisham"
                    onChange={(e) => set("role", e.target.value)}
                  />
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-2">
                  <Label htmlFor="t-rating">Rating</Label>
                  <Select value={String(draft.rating)} onValueChange={(v) => set("rating", Number(v))}>
                    <SelectTrigger id="t-rating">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {[5, 4, 3, 2, 1].map((n) => (
                        <SelectItem key={n} value={String(n)}>
                          {n} star{n > 1 ? "s" : ""}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="t-status">Status</Label>
                  <Select value={draft.status} onValueChange={(v) => set("status", v as Item["status"])}>
                    <SelectTrigger id="t-status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="published">Published</SelectItem>
                      <SelectItem value="draft">Draft</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="t-sort">Sort order</Label>
                  <Input
                    id="t-sort"
                    type="number"
                    value={draft.sort_order}
                    onChange={(e) => set("sort_order", Number(e.target.value))}
                  />
                </div>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="t-body">Testimonial</Label>
                <Textarea id="t-body" rows={4} value={draft.body} onChange={(e) => set("body", e.target.value)} />
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setDraft(null)} disabled={saving}>
              Cancel
            </Button>
            <Button onClick={save} disabled={saving}>
              {saving ? "Saving…" : isEdit ? "Save changes" : "Add testimonial"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
