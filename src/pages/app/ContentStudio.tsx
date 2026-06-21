import { useState } from "react"
import { Sparkles, Copy, Check, Loader2, FileText, Hash, Search, Image as ImageIcon, Download } from "lucide-react"
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
import { api, aiDraft, ApiError } from "@/lib/api"
import { cn } from "@/lib/utils"
import { CATEGORIES } from "@/lib/resources"

const CHANNELS = ["Blog", "LinkedIn", "X", "Instagram", "Facebook", "TikTok", "Google Business", "Email"] as const
type Channel = (typeof CHANNELS)[number]

const TONES = ["Warm & plain", "Professional", "Confident & punchy", "Reassuring"] as const
const BLOG_CATS = CATEGORIES.filter((c) => c !== "All")

type BlogDraft = { title: string; excerpt: string; category: string; body: string }
type SocialPost = { platform: string; text: string; hashtags: string[] }
type Seo = { metaTitle: string; metaDescription: string; slug: string }
type Card = { headline: string; sub: string }
type Result = {
  blog?: BlogDraft
  social: SocialPost[]
  seo?: Seo
  imagePrompt?: string
  card?: Card
  keywords: string[]
}

const SYSTEM =
  "You are RitaGreen's content lead. RitaGreen is a London care, training, cleaning and security group. " +
  "Brand voice: warm, clear, plain and accountable; UK English; lead with reassurance, never hype. " +
  "Respond with ONLY valid JSON — no markdown fences, no commentary."

function extractJson(reply: string): unknown {
  let s = reply.trim()
  const fence = s.match(/```(?:json)?\s*([\s\S]*?)```/i)
  if (fence) s = fence[1].trim()
  const a = s.indexOf("{")
  const b = s.lastIndexOf("}")
  if (a >= 0 && b > a) s = s.slice(a, b + 1)
  return JSON.parse(s)
}

/* ---- On-brand social card (client-side, no image API) ---- */

function escapeXml(s: string) {
  return s.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c] as string))
}

function wrap(text: string, maxChars: number): string[] {
  const words = (text || "").split(/\s+/).filter(Boolean)
  const lines: string[] = []
  let line = ""
  for (const w of words) {
    if (line && (line + " " + w).length > maxChars) {
      lines.push(line)
      line = w
    } else {
      line = line ? line + " " + w : w
    }
  }
  if (line) lines.push(line)
  return lines.slice(0, 4)
}

function buildCardSvg(card: Card): string {
  const navy = "#0C447C"
  const mint = "#5DCAA5"
  const font = "'Plus Jakarta Sans','Segoe UI',Arial,sans-serif"
  const hl = wrap(card.headline, 17)
  const hlStart = 560 - (hl.length - 1) * 52
  const hlTspans = hl
    .map((l, i) => `<tspan x="96" dy="${i === 0 ? 0 : 104}">${escapeXml(l)}</tspan>`)
    .join("")
  const sub = wrap(card.sub, 42)
  const subTspans = sub.map((l, i) => `<tspan x="96" dy="${i === 0 ? 0 : 44}">${escapeXml(l)}</tspan>`).join("")
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1080" viewBox="0 0 1080 1080" style="width:100%;height:auto;display:block">
  <rect width="1080" height="1080" fill="${navy}"/>
  <circle cx="980" cy="120" r="260" fill="#ffffff" opacity="0.05"/>
  <rect x="0" y="0" width="1080" height="10" fill="${mint}"/>
  <g transform="translate(96,96)">
    <rect width="64" height="64" rx="16" fill="${mint}"/>
    <path d="M20 46c0-12 9-22 24-24-2 12-11 21-24 24z" fill="#0C447C"/>
    <text x="86" y="44" font-family="${font}" font-size="38" font-weight="800" fill="#ffffff">RitaGreen</text>
  </g>
  <text x="96" y="430" font-family="${font}" font-size="26" letter-spacing="5" fill="${mint}" font-weight="700">RITAGREEN SERVICES</text>
  <text y="${hlStart}" font-family="${font}" font-size="92" font-weight="800" fill="#ffffff">${hlTspans}</text>
  <text y="760" font-family="${font}" font-size="36" fill="#c6d6ea">${subTspans}</text>
  <text x="96" y="1004" font-family="${font}" font-size="26" fill="#9fb6d1">ritagreenservices.com</text>
  <text x="96" y="1044" font-family="${font}" font-size="22" fill="#7c97b8">Care · Training · Cleaning · Security</text>
</svg>`
}

export default function ContentStudio() {
  const [topic, setTopic] = useState("")
  const [keyword, setKeyword] = useState("")
  const [tone, setTone] = useState<string>(TONES[0])
  const [channels, setChannels] = useState<Set<Channel>>(new Set(CHANNELS))
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<Result | null>(null)
  const [blog, setBlog] = useState<BlogDraft | null>(null)
  const [card, setCard] = useState<Card | null>(null)
  const [savingBlog, setSavingBlog] = useState(false)
  const [copied, setCopied] = useState<string | null>(null)

  function toggle(ch: Channel) {
    setChannels((prev) => {
      const next = new Set(prev)
      if (next.has(ch)) next.delete(ch)
      else next.add(ch)
      return next
    })
  }

  async function copy(text: string, key: string) {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(key)
      setTimeout(() => setCopied((c) => (c === key ? null : c)), 1500)
    } catch {
      toast.error("Couldn't copy to clipboard.")
    }
  }

  async function generate() {
    if (!topic.trim()) {
      toast.error("Give the studio a topic or brief to work from.")
      return
    }
    const wantBlog = channels.has("Blog")
    const socials = [...channels].filter((c) => c !== "Blog")
    if (!wantBlog && socials.length === 0) {
      toast.error("Pick at least one channel.")
      return
    }

    const prompt = [
      `Create a complete content pack about: "${topic.trim()}".`,
      keyword.trim() ? `Weave in this primary keyword naturally: "${keyword.trim()}".` : "",
      `Tone: ${tone}.`,
      "Return a single JSON object with these keys:",
      wantBlog
        ? `"blog": { "title": string, "excerpt": string (max 160 chars), "category": one of [${BLOG_CATS.join(", ")}], "body": string of 4-6 paragraphs separated by blank lines },`
        : "",
      socials.length
        ? `"social": array containing ONLY these platforms [${socials.join(", ")}], each { "platform": string, "text": string sized for that platform (Email = short newsletter blurb, Google Business = short update), "hashtags": array (empty for Email and Google Business; otherwise 5-8 strings each starting with #) },`
        : `"social": [],`,
      `"seo": { "metaTitle": string (max 60 chars), "metaDescription": string (max 155 chars), "slug": kebab-case string },`,
      `"imagePrompt": a vivid prompt for an AI image generator to make an on-brand hero image (photographic, no text in the image),`,
      `"card": { "headline": punchy line max 9 words, "sub": supporting line max 12 words },`,
      `"keywords": array of 6-10 relevant SEO keywords.`,
      "UK English. On-brand. No platforms beyond those listed.",
    ]
      .filter(Boolean)
      .join("\n")

    setLoading(true)
    setResult(null)
    setBlog(null)
    setCard(null)
    try {
      const reply = await aiDraft(prompt, SYSTEM)
      const parsed = extractJson(reply) as Result
      const normalised: Result = {
        blog: wantBlog && parsed.blog ? parsed.blog : undefined,
        social: Array.isArray(parsed.social) ? parsed.social : [],
        seo: parsed.seo,
        imagePrompt: typeof parsed.imagePrompt === "string" ? parsed.imagePrompt : undefined,
        card: parsed.card,
        keywords: Array.isArray(parsed.keywords) ? parsed.keywords : [],
      }
      setResult(normalised)
      if (normalised.blog) setBlog(normalised.blog)
      if (normalised.card) setCard(normalised.card)
    } catch (err) {
      if (err instanceof ApiError) toast.error(err.message)
      else toast.error("The AI returned something we couldn't read. Try generating again.")
    } finally {
      setLoading(false)
    }
  }

  async function saveBlogDraft() {
    if (!blog) return
    if (!blog.title.trim()) {
      toast.error("The blog needs a title before saving.")
      return
    }
    setSavingBlog(true)
    try {
      await api("blog.php?action=create", {
        method: "POST",
        body: {
          title: blog.title.trim(),
          excerpt: blog.excerpt.trim(),
          category: blog.category || "Company",
          body: blog.body,
          status: "draft",
        },
      })
      toast.success("Saved as a draft in Blog. Review and publish it there.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the draft.")
    } finally {
      setSavingBlog(false)
    }
  }

  function downloadCard() {
    if (!card) return
    const svg = buildCardSvg(card)
    const url = URL.createObjectURL(new Blob([svg], { type: "image/svg+xml" }))
    const img = new Image()
    img.onload = () => {
      const c = document.createElement("canvas")
      c.width = 1080
      c.height = 1080
      const ctx = c.getContext("2d")
      if (!ctx) return
      ctx.drawImage(img, 0, 0)
      URL.revokeObjectURL(url)
      c.toBlob((b) => {
        if (!b) return
        const a = document.createElement("a")
        a.href = URL.createObjectURL(b)
        a.download = "ritagreen-social-card.png"
        a.click()
        URL.revokeObjectURL(a.href)
      }, "image/png")
    }
    img.onerror = () => toast.error("Couldn't render the card image.")
    img.src = url
  }

  function CopyBtn({ text, k, label = "Copy" }: { text: string; k: string; label?: string }) {
    return (
      <Button variant="ghost" size="sm" onClick={() => copy(text, k)}>
        {copied === k ? (
          <>
            <Check className="mr-1 size-4 text-success" /> Copied
          </>
        ) : (
          <>
            <Copy className="mr-1 size-4" /> {label}
          </>
        )}
      </Button>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
          <Sparkles className="size-5 text-primary" /> Content Studio
        </h1>
        <p className="text-sm text-muted-foreground">
          One brief → a blog post, social posts for every channel, SEO, an image prompt and an
          on-brand graphic. Review and edit everything before it goes out.
        </p>
      </div>

      {/* Brief */}
      <div className="surface space-y-4 p-6">
        <div className="grid gap-2">
          <Label htmlFor="cs-topic">Topic or brief</Label>
          <Textarea
            id="cs-topic"
            rows={3}
            value={topic}
            placeholder="e.g. Why care homes should check SIA licences before every shift"
            onChange={(e) => setTopic(e.target.value)}
          />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2">
            <Label htmlFor="cs-keyword">Primary keyword (optional)</Label>
            <Input
              id="cs-keyword"
              value={keyword}
              placeholder="e.g. care staffing London"
              onChange={(e) => setKeyword(e.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="cs-tone">Tone</Label>
            <Select value={tone} onValueChange={setTone}>
              <SelectTrigger id="cs-tone">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {TONES.map((t) => (
                  <SelectItem key={t} value={t}>
                    {t}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="grid gap-2">
          <Label>Channels</Label>
          <div className="flex flex-wrap gap-2">
            {CHANNELS.map((ch) => {
              const on = channels.has(ch)
              return (
                <button
                  key={ch}
                  type="button"
                  onClick={() => toggle(ch)}
                  aria-pressed={on}
                  className={cn(
                    "rounded-full border px-4 py-1.5 text-sm font-medium transition-colors",
                    on
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground",
                  )}
                >
                  {ch}
                </button>
              )
            })}
          </div>
        </div>
        <Button onClick={generate} disabled={loading} size="lg">
          {loading ? (
            <>
              <Loader2 className="mr-1 size-4 animate-spin" /> Generating…
            </>
          ) : (
            <>
              <Sparkles className="mr-1 size-4" /> Generate content
            </>
          )}
        </Button>
        <p className="text-xs text-muted-foreground">
          AI drafts are a starting point — review for accuracy and brand fit before publishing.
          Keyword suggestions are AI-generated, not live trend data.
        </p>
      </div>

      {/* Results */}
      {result && (
        <div className="space-y-6">
          {/* Keywords + SEO */}
          <div className="grid gap-6 lg:grid-cols-2">
            {result.keywords.length > 0 && (
              <div className="surface p-5">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                  <Hash className="size-4 text-primary" /> Suggested keywords
                </h2>
                <div className="mt-3 flex flex-wrap gap-2">
                  {result.keywords.map((k) => (
                    <Badge key={k} variant="secondary">
                      {k}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
            {result.seo && (
              <div className="surface p-5">
                <div className="flex items-center justify-between">
                  <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Search className="size-4 text-primary" /> SEO
                  </h2>
                  <CopyBtn
                    k="seo"
                    text={`Title: ${result.seo.metaTitle}\nDescription: ${result.seo.metaDescription}\nSlug: ${result.seo.slug}`}
                    label="Copy all"
                  />
                </div>
                <dl className="mt-3 space-y-2 text-sm">
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">Meta title</dt>
                    <dd className="text-foreground">{result.seo.metaTitle}</dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">Meta description</dt>
                    <dd className="text-foreground">{result.seo.metaDescription}</dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium text-muted-foreground">URL slug</dt>
                    <dd className="font-mono text-xs text-foreground">{result.seo.slug}</dd>
                  </div>
                </dl>
              </div>
            )}
          </div>

          {/* On-brand card */}
          {card && (
            <div className="surface p-6">
              <h2 className="flex items-center gap-2 font-semibold">
                <ImageIcon className="size-4 text-primary" /> On-brand social card
              </h2>
              <div className="mt-4 grid gap-6 md:grid-cols-[260px_1fr] md:items-start">
                <div
                  className="w-full max-w-[260px] overflow-hidden rounded-xl border border-border shadow-soft"
                  dangerouslySetInnerHTML={{ __html: buildCardSvg(card) }}
                />
                <div className="space-y-3">
                  <div className="grid gap-2">
                    <Label htmlFor="cs-card-h">Headline</Label>
                    <Input id="cs-card-h" value={card.headline} onChange={(e) => setCard({ ...card, headline: e.target.value })} />
                  </div>
                  <div className="grid gap-2">
                    <Label htmlFor="cs-card-s">Sub-line</Label>
                    <Input id="cs-card-s" value={card.sub} onChange={(e) => setCard({ ...card, sub: e.target.value })} />
                  </div>
                  <Button onClick={downloadCard} variant="outline">
                    <Download className="mr-1 size-4" /> Download PNG (1080×1080)
                  </Button>
                </div>
              </div>
            </div>
          )}

          {/* Blog draft */}
          {blog && (
            <div className="surface space-y-4 p-6">
              <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 font-semibold">
                  <FileText className="size-4 text-primary" /> Blog draft
                </h2>
                <Button onClick={saveBlogDraft} disabled={savingBlog}>
                  {savingBlog ? "Saving…" : "Save as blog draft"}
                </Button>
              </div>
              <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                <div className="grid gap-2">
                  <Label htmlFor="cs-bt">Title</Label>
                  <Input id="cs-bt" value={blog.title} onChange={(e) => setBlog({ ...blog, title: e.target.value })} />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="cs-bc">Category</Label>
                  <Select value={blog.category} onValueChange={(v) => setBlog({ ...blog, category: v })}>
                    <SelectTrigger id="cs-bc" className="min-w-36">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {BLOG_CATS.map((c) => (
                        <SelectItem key={c} value={c}>
                          {c}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid gap-2">
                <Label htmlFor="cs-be">Excerpt</Label>
                <Textarea id="cs-be" rows={2} value={blog.excerpt} onChange={(e) => setBlog({ ...blog, excerpt: e.target.value })} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="cs-bb">Body</Label>
                <Textarea id="cs-bb" rows={12} value={blog.body} onChange={(e) => setBlog({ ...blog, body: e.target.value })} />
              </div>
            </div>
          )}

          {/* Social posts */}
          {result.social.length > 0 && (
            <div className="grid gap-5 md:grid-cols-2">
              {result.social.map((p) => (
                <div key={p.platform} className="surface flex flex-col p-6">
                  <div className="flex items-center justify-between">
                    <h3 className="font-semibold">{p.platform}</h3>
                    <CopyBtn k={`s-${p.platform}`} text={[p.text, p.hashtags?.join(" ")].filter(Boolean).join("\n\n")} />
                  </div>
                  <p className="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-foreground">{p.text}</p>
                  {p.hashtags?.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-1.5 border-t border-border/60 pt-3">
                      {p.hashtags.map((h) => (
                        <span key={h} className="text-xs font-medium text-primary">
                          {h}
                        </span>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          {/* Image prompt */}
          {result.imagePrompt && (
            <div className="surface p-6">
              <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 font-semibold">
                  <ImageIcon className="size-4 text-primary" /> Image prompt
                </h2>
                <CopyBtn k="imgprompt" text={result.imagePrompt} />
              </div>
              <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{result.imagePrompt}</p>
              <p className="mt-2 text-xs text-muted-foreground">
                Paste into your image tool (Midjourney, DALL·E, etc.). Live in-app image generation
                needs an image-gen API key — a future phase.
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
