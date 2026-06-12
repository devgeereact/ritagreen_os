import { useState } from "react"
import { Link, useParams } from "react-router-dom"
import { ArrowLeft, BadgeCheck, Plus, Sparkles, Copy, Check } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
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
import { useApi } from "@/hooks/use-api"
import { api, aiDraft, ApiError } from "@/lib/api"
import type { Programme, Enrolment } from "@/lib/types"
import { DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUS_VARIANT: Record<Enrolment["status"], "default" | "secondary" | "outline" | "destructive"> = {
  completed: "default",
  enrolled: "secondary",
  in_progress: "secondary",
  withdrawn: "destructive",
}

export default function AcademyDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useApi<{ programme: Programme; enrolments: Enrolment[] }>(
    id ? `academy.php?id=${id}` : null,
    [id],
  )
  const [learner, setLearner] = useState("")
  const [enrolling, setEnrolling] = useState(false)

  async function enrol(e: React.FormEvent) {
    e.preventDefault()
    if (!learner.trim()) return
    setEnrolling(true)
    try {
      await api("academy.php?action=enrol", {
        method: "POST",
        body: { programme_id: Number(id), learner_name: learner.trim() },
      })
      toast.success("Learner enrolled.")
      setLearner("")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not enrol the learner.")
    } finally {
      setEnrolling(false)
    }
  }

  async function complete(enrolmentId: number) {
    try {
      const res = await api<{ public_id: string }>(`academy.php?action=complete&enrolment_id=${enrolmentId}`, {
        method: "POST",
        body: {},
      })
      toast.success(`Certificate issued: ${res.public_id}`)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not issue the certificate.")
    }
  }

  return (
    <div className="space-y-6">
      <Link to="/app/academy" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> All programmes
      </Link>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold tracking-tight">{data.programme.title}</h1>
              <p className="text-sm text-muted-foreground">{DIVISION_LABELS[data.programme.division] ?? data.programme.division}</p>
            </div>
            <Badge className="capitalize">{data.programme.status}</Badge>
          </div>

          {data.programme.description && (
            <Card>
              <CardHeader><CardTitle className="text-base">About this programme</CardTitle></CardHeader>
              <CardContent className="whitespace-pre-wrap text-sm text-muted-foreground">
                {data.programme.description}
              </CardContent>
            </Card>
          )}

          <QuizBuilder programme={data.programme} />

          <Card>
            <CardHeader><CardTitle className="text-base">Enrol a learner</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={enrol} className="flex gap-2">
                <Input value={learner} onChange={(e) => setLearner(e.target.value)} placeholder="Learner full name" />
                <Button type="submit" disabled={enrolling}>
                  <Plus className="mr-1 size-4" /> Enrol
                </Button>
              </form>
            </CardContent>
          </Card>

          {data.enrolments.length === 0 ? (
            <EmptyBlock title="No learners yet" hint="Enrol a learner above to get started." />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Learner</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Certificate</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.enrolments.map((en) => (
                    <TableRow key={en.id}>
                      <TableCell className="font-medium">{en.learner_name}</TableCell>
                      <TableCell><Badge variant={STATUS_VARIANT[en.status]} className="capitalize">{en.status.replace("_", " ")}</Badge></TableCell>
                      <TableCell>
                        {en.certificate_public_id ? (
                          <Link
                            to={`/verify/${en.certificate_public_id}`}
                            className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                          >
                            <BadgeCheck className="size-4" /> {en.certificate_public_id}
                          </Link>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        {en.status !== "completed" && (
                          <Button size="sm" variant="outline" onClick={() => complete(en.id)}>
                            Complete & issue
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )}
        </>
      )}
    </div>
  )
}

type QuizQuestion = { question: string; options: string[]; answer: number }

// AI assessment builder — generates a multiple-choice quiz from the programme
// via ai.php. No persistence: staff generate, review, then copy into their LMS
// or print. Output is requested as JSON and parsed defensively.
function QuizBuilder({ programme }: { programme: Programme }) {
  const [count, setCount] = useState("5")
  const [busy, setBusy] = useState(false)
  const [quiz, setQuiz] = useState<QuizQuestion[] | null>(null)
  const [raw, setRaw] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  async function generate() {
    setBusy(true)
    setQuiz(null)
    setRaw(null)
    try {
      const reply = await aiDraft(
        `Create a ${count}-question multiple-choice assessment for this RitaGreen training programme. ` +
          `Each question has exactly 4 options and one correct answer. Pitch it at UK vocational level, ` +
          `UK English, practical and relevant to the role.\n\n` +
          `Programme: ${programme.title}\n` +
          `Division: ${DIVISION_LABELS[programme.division] ?? programme.division}\n` +
          `Description: ${programme.description || "(none provided)"}\n\n` +
          `Reply with ONLY a JSON array, no prose, no markdown fences. Shape: ` +
          `[{"question":"...","options":["a","b","c","d"],"answer":0}] where "answer" is the ` +
          `0-based index of the correct option.`,
        "You are an instructional designer for a UK vocational training academy. You output strict JSON only.",
      )
      const parsed = parseQuiz(reply)
      if (parsed) setQuiz(parsed)
      else setRaw(reply)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Quiz generation failed.")
    } finally {
      setBusy(false)
    }
  }

  function copy() {
    const text = quiz
      ? quiz
          .map(
            (q, i) =>
              `${i + 1}. ${q.question}\n` +
              q.options.map((o, j) => `   ${String.fromCharCode(65 + j)}) ${o}`).join("\n") +
              `\n   Answer: ${String.fromCharCode(65 + q.answer)}`,
          )
          .join("\n\n")
      : (raw ?? "")
    navigator.clipboard.writeText(text)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Sparkles className="size-4 text-primary" /> AI assessment builder
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-end gap-2">
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">Questions</label>
            <Select value={count} onValueChange={setCount}>
              <SelectTrigger className="w-24"><SelectValue /></SelectTrigger>
              <SelectContent>
                {["3", "5", "10"].map((n) => (
                  <SelectItem key={n} value={n}>{n}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button onClick={generate} disabled={busy}>
            <Sparkles className="mr-1 size-4" /> {busy ? "Generating…" : "Generate quiz"}
          </Button>
          {(quiz || raw) && (
            <Button variant="ghost" onClick={copy}>
              {copied ? <Check className="mr-1 size-3.5" /> : <Copy className="mr-1 size-3.5" />}
              {copied ? "Copied" : "Copy"}
            </Button>
          )}
        </div>

        {!quiz && !raw && !busy && (
          <p className="text-sm text-muted-foreground">
            Draft a multiple-choice assessment for this programme. Review and edit before using — AI can make mistakes.
          </p>
        )}

        {quiz && (
          <ol className="space-y-4">
            {quiz.map((q, i) => (
              <li key={i} className="space-y-1.5">
                <p className="text-sm font-medium">{i + 1}. {q.question}</p>
                <ul className="space-y-1">
                  {q.options.map((o, j) => (
                    <li
                      key={j}
                      className={
                        j === q.answer
                          ? "flex items-center gap-2 rounded-md bg-primary/10 px-2 py-1 text-sm font-medium text-foreground"
                          : "flex items-center gap-2 px-2 py-1 text-sm text-muted-foreground"
                      }
                    >
                      <span className="font-mono text-xs">{String.fromCharCode(65 + j)})</span>
                      {o}
                      {j === q.answer && <Check className="size-3.5 text-primary" />}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ol>
        )}

        {raw && (
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">Couldn't format as a quiz — raw AI output:</p>
            <pre className="whitespace-pre-wrap rounded-md bg-muted p-3 text-sm">{raw}</pre>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// Parse the AI reply into quiz questions. Tolerates markdown fences and any
// prose around the JSON array; returns null if nothing usable comes back.
function parseQuiz(reply: string): QuizQuestion[] | null {
  const start = reply.indexOf("[")
  const end = reply.lastIndexOf("]")
  if (start === -1 || end <= start) return null
  try {
    const arr = JSON.parse(reply.slice(start, end + 1))
    if (!Array.isArray(arr)) return null
    const cleaned = arr
      .filter(
        (q): q is QuizQuestion =>
          q &&
          typeof q.question === "string" &&
          Array.isArray(q.options) &&
          q.options.length >= 2 &&
          q.options.every((o: unknown) => typeof o === "string") &&
          Number.isInteger(q.answer) &&
          q.answer >= 0 &&
          q.answer < q.options.length,
      )
      .map((q) => ({ question: q.question, options: q.options, answer: q.answer }))
    return cleaned.length > 0 ? cleaned : null
  } catch {
    return null
  }
}
