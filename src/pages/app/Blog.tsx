import { useState } from "react"
import { Plus, Pencil, Trash2, ExternalLink } from "lucide-react"
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
import { CATEGORIES } from "@/lib/resources"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Post = {
  id: number
  slug: string
  title: string
  excerpt: string
  category: string
  image: string
  body: string
  read_mins: number
  status: "draft" | "published"
  published_at: string | null
}

const CATS = CATEGORIES.filter((c) => c !== "All")

const EMPTY: Post = {
  id: 0,
  slug: "",
  title: "",
  excerpt: "",
  category: "Company",
  image: "",
  body: "",
  read_mins: 0,
  status: "draft",
  published_at: null,
}

function shortDate(d: string | null) {
  if (!d) return "—"
  return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })
}

export default function Blog() {
  const { data, loading, error, reload } = useApi<{ posts: Post[] }>("blog.php?action=all")
  const [draft, setDraft] = useState<Post | null>(null)
  const [saving, setSaving] = useState(false)

  const posts = data?.posts ?? []
  const isEdit = !!draft && draft.id > 0

  function set<K extends keyof Post>(key: K, value: Post[K]) {
    setDraft((d) => (d ? { ...d, [key]: value } : d))
  }

  async function save() {
    if (!draft) return
    if (!draft.title.trim()) {
      toast.error("A title is required.")
      return
    }
    setSaving(true)
    try {
      const qs = isEdit ? `blog.php?action=update&id=${draft.id}` : "blog.php?action=create"
      await api(qs, {
        method: "POST",
        body: {
          slug: draft.slug.trim(),
          title: draft.title.trim(),
          excerpt: draft.excerpt.trim(),
          category: draft.category,
          image: draft.image.trim(),
          body: draft.body,
          read_mins: draft.read_mins || 0,
          status: draft.status,
          published_at: draft.published_at || "",
        },
      })
      toast.success(isEdit ? "Post updated." : "Post created.")
      setDraft(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the post.")
    } finally {
      setSaving(false)
    }
  }

  async function remove(post: Post) {
    if (!confirm(`Delete "${post.title}"? This can't be undone from here.`)) return
    try {
      await api(`blog.php?action=delete&id=${post.id}`, { method: "POST", body: {} })
      toast.success("Post deleted.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not delete the post.")
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Blog</h1>
          <p className="text-sm text-muted-foreground">
            Posts published here appear on the public Resources page.
          </p>
        </div>
        <Button onClick={() => setDraft({ ...EMPTY })}>
          <Plus className="mr-1 size-4" /> New post
        </Button>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && posts.length === 0 && (
        <EmptyBlock title="No posts yet" hint="Create your first post to publish it on the website." />
      )}

      {posts.length > 0 && (
        <div className="surface overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Title</TableHead>
                <TableHead>Category</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Published</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {posts.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="font-medium">{p.title}</TableCell>
                  <TableCell className="text-muted-foreground">{p.category}</TableCell>
                  <TableCell>
                    <Badge variant={p.status === "published" ? "default" : "secondary"}>
                      {p.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground tabular">{shortDate(p.published_at)}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      {p.status === "published" && (
                        <Button asChild variant="ghost" size="icon" aria-label="View on site">
                          <a href={`/resources/${p.slug}`} target="_blank" rel="noopener noreferrer">
                            <ExternalLink className="size-4" />
                          </a>
                        </Button>
                      )}
                      <Button variant="ghost" size="icon" onClick={() => setDraft({ ...p })} aria-label="Edit">
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => remove(p)}
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

      {/* Editor */}
      <Dialog open={!!draft} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{isEdit ? "Edit post" : "New post"}</DialogTitle>
            <DialogDescription>
              Set the post to “published” to make it live on the public site.
            </DialogDescription>
          </DialogHeader>

          {draft && (
            <div className="space-y-4">
              <div className="grid gap-2">
                <Label htmlFor="b-title">Title</Label>
                <Input id="b-title" value={draft.title} onChange={(e) => set("title", e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="b-slug">URL slug (optional)</Label>
                <Input
                  id="b-slug"
                  value={draft.slug}
                  placeholder="auto-generated from the title"
                  onChange={(e) => set("slug", e.target.value)}
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="b-cat">Category</Label>
                  <Select value={draft.category} onValueChange={(v) => set("category", v)}>
                    <SelectTrigger id="b-cat">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CATS.map((c) => (
                        <SelectItem key={c} value={c}>
                          {c}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="b-status">Status</Label>
                  <Select value={draft.status} onValueChange={(v) => set("status", v as Post["status"])}>
                    <SelectTrigger id="b-status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="draft">Draft</SelectItem>
                      <SelectItem value="published">Published</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="b-date">Publish date</Label>
                  <Input
                    id="b-date"
                    type="date"
                    value={draft.published_at ?? ""}
                    onChange={(e) => set("published_at", e.target.value)}
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="b-read">Read time (mins, 0 = auto)</Label>
                  <Input
                    id="b-read"
                    type="number"
                    min={0}
                    value={draft.read_mins}
                    onChange={(e) => set("read_mins", Number(e.target.value))}
                  />
                </div>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="b-image">Cover image URL (optional)</Label>
                <Input
                  id="b-image"
                  value={draft.image}
                  placeholder="/images/divisions/care.jpg or https://…"
                  onChange={(e) => set("image", e.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="b-excerpt">Excerpt</Label>
                <Textarea
                  id="b-excerpt"
                  rows={2}
                  value={draft.excerpt}
                  onChange={(e) => set("excerpt", e.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="b-body">Body</Label>
                <Textarea
                  id="b-body"
                  rows={10}
                  value={draft.body}
                  placeholder="Write the article. Separate paragraphs with a blank line."
                  onChange={(e) => set("body", e.target.value)}
                />
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setDraft(null)} disabled={saving}>
              Cancel
            </Button>
            <Button onClick={save} disabled={saving}>
              {saving ? "Saving…" : isEdit ? "Save changes" : "Create post"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
