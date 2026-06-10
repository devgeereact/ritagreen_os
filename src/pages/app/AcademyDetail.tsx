import { useState } from "react"
import { Link, useParams } from "react-router-dom"
import { ArrowLeft, BadgeCheck, Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
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
