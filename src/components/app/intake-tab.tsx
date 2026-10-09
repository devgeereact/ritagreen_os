import { useState } from "react"
import { Link } from "react-router-dom"
import { toast } from "sonner"
import { AlertTriangle, KeyRound, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"
import { IntakeBadge } from "@/components/app/intake-badge"
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { useAuth } from "@/providers/auth-provider"
import type { WebsiteForm } from "@/lib/types"

/**
 * Linked form intake: is it working, and what has it dropped.
 *
 * forms-intake.php has served this since migration 011 and nothing in src/ called
 * it, so a form that silently stopped feeding the system looked exactly like a
 * quiet week, and a submission that failed to map was a person who applied and
 * then vanished. Both are now on a screen somebody looks at.
 *
 * Section S7 added the website's forms directory above them (forms not reviewed
 * for 90 days, and each form's intake health), and the owner's "Set a new
 * secret" action, which is how an integration gets the shared secret its Apps
 * Script sends. See docs/runbooks/google-forms-intake.md.
 */

type Integration = {
  id: number
  integration_key: string
  name: string
  active: boolean
  configured: boolean
  default_service: string | null
  last_success_at: string | null
  last_failure_at: string | null
  last_failure_reason: string | null
  submissions_total: number
  /** True when nothing has arrived for longer than the form's expected gap. */
  silent: boolean
}

type Submission = {
  id: number
  integration_name?: string | null
  external_id: string | null
  status: "received" | "mapped" | "failed" | "ignored"
  failure_reason: string | null
  applicant_id: number | null
  received_at: string
}

const STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  mapped: "default", received: "secondary", failed: "destructive", ignored: "outline",
}

export function IntakeTab() {
  const { hasRole } = useAuth()
  const isOwner = hasRole("super_admin", "admin")
  const health = useApi<{ integrations: Integration[] }>("forms-intake.php?action=health")
  const subs = useApi<{ submissions: Submission[] }>("forms-intake.php?action=submissions")
  const websiteForms = useApi<{ forms: WebsiteForm[] }>("website-forms.php")
  const [shown, setShown] = useState<{ key: string; secret: string } | null>(null)

  async function setSecret(i: Integration) {
    const replacing = i.configured
      ? "This replaces the current secret. The form stops feeding the system until its Apps Script is given the new one. Continue?"
      : "Create a secret for this form?"
    if (!window.confirm(replacing)) return
    try {
      const res = await api<{ integration_key: string; secret: string }>(
        `forms-intake.php?action=set_secret&id=${i.id}`, { method: "POST", body: {} },
      )
      setShown({ key: res.integration_key, secret: res.secret })
      health.reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not set the secret.")
    }
  }

  async function retry(id: number) {
    try {
      const res = await api<{ ok: boolean; already_mapped?: boolean; applicant_id?: number }>(
        `forms-intake.php?action=retry&id=${id}`, { method: "POST", body: {} },
      )
      toast.success(res.already_mapped
        ? "That submission had already been mapped — nothing was duplicated."
        : "Submission mapped.")
      health.reload(); subs.reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not retry that submission.")
    }
  }

  const integrations = health.data?.integrations ?? []
  const submissions = subs.data?.submissions ?? []
  const failed = submissions.filter((s) => s.status === "failed")

  const forms = websiteForms.data?.forms ?? []
  const dueForms = forms.filter((f) => f.active && f.review_due)

  return (
    <div className="space-y-6">
      <section className="space-y-2">
        <h2 className="text-sm font-semibold">
          Website forms
          {dueForms.length > 0 && <span className="ml-2 text-destructive">{dueForms.length} not reviewed for 90 days</span>}
        </h2>
        <p className="text-xs text-muted-foreground">
          The Google Forms on role pages, from <Link to="/app/website-forms" className="underline">Website, Forms</Link>.
        </p>
        {websiteForms.loading && <LoadingBlock rows={2} />}
        {websiteForms.error && <ErrorBlock message={websiteForms.error} onRetry={websiteForms.reload} />}
        {websiteForms.data && (
          forms.length === 0 ? (
            <EmptyBlock title="No website forms" hint="Add one in Website, Forms." />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Form</TableHead>
                    <TableHead>Intake</TableHead>
                    <TableHead>Last reviewed</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {forms.map((f) => (
                    <TableRow key={f.id}>
                      <TableCell>
                        <div className="font-medium">{f.title}{!f.active && <Badge variant="secondary" className="ml-2">Off</Badge>}</div>
                        <div className="text-xs text-muted-foreground">{f.integration_name ?? "Not connected to an intake key"}</div>
                      </TableCell>
                      <TableCell><IntakeBadge status={f.intake_status} /></TableCell>
                      <TableCell className="text-sm">
                        {f.last_reviewed_at ? shortDate(f.last_reviewed_at) : "Never"}
                        {f.active && f.review_due && <Badge variant="destructive" className="ml-2">Review due</Badge>}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )
        )}
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-semibold">Intake keys</h2>
        {health.loading && <LoadingBlock rows={3} />}
        {health.error && <ErrorBlock message={health.error} onRetry={health.reload} />}
        {health.data && (
          integrations.length === 0 ? (
            <EmptyBlock title="No intake keys" hint="Intake keys are created by migration 011 and post to forms-intake.php." />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Form</TableHead>
                    <TableHead>State</TableHead>
                    <TableHead>Last submission</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    {isOwner && <TableHead></TableHead>}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {integrations.map((i) => (
                    <TableRow key={i.id}>
                      <TableCell>
                        <div className="font-medium">{i.name}</div>
                        <div className="text-xs text-muted-foreground">{i.integration_key}</div>
                      </TableCell>
                      <TableCell className="space-x-1">
                        {!i.active && <Badge variant="secondary">Paused</Badge>}
                        {/* A form with no secret refuses every submission, which is
                            indistinguishable from nobody applying unless it is said. */}
                        {!i.configured && <Badge variant="destructive">No secret set</Badge>}
                        {i.silent && <Badge variant="destructive">Nothing received</Badge>}
                        {i.active && i.configured && !i.silent && <Badge variant="default">Working</Badge>}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {i.last_success_at ? shortDate(i.last_success_at) : "never"}
                        {i.last_failure_reason && (
                          <div className="flex items-center gap-1 text-xs text-destructive">
                            <AlertTriangle className="size-3" /> {i.last_failure_reason}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{i.submissions_total}</TableCell>
                      {isOwner && (
                        <TableCell className="text-right">
                          <Button size="sm" variant="outline" onClick={() => setSecret(i)}>
                            <KeyRound className="mr-1 size-3.5" /> {i.configured ? "New secret" : "Set secret"}
                          </Button>
                        </TableCell>
                      )}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )
        )}
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-semibold">
          Recent submissions
          {failed.length > 0 && <span className="ml-2 text-destructive">{failed.length} need attention</span>}
        </h2>
        <p className="text-xs text-muted-foreground">
          A submission that could not be mapped is still a person who applied. The answers they
          sent are kept, so fixing the form mapping and retrying recovers them.
        </p>
        {subs.loading && <LoadingBlock rows={3} />}
        {subs.error && <ErrorBlock message={subs.error} onRetry={subs.reload} />}
        {subs.data && (
          submissions.length === 0 ? (
            <EmptyBlock title="No submissions yet" hint="Submissions appear here as forms post them." />
          ) : (
            <Card>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Received</TableHead>
                    <TableHead>Response</TableHead>
                    <TableHead>Result</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {submissions.map((s) => (
                    <TableRow key={s.id}>
                      <TableCell className="text-sm text-muted-foreground">{shortDate(s.received_at)}</TableCell>
                      <TableCell className="font-mono text-xs">{s.external_id ?? "—"}</TableCell>
                      <TableCell>
                        <Badge variant={STATUS_VARIANT[s.status]}>{s.status}</Badge>
                        {s.failure_reason && (
                          <div className="mt-0.5 text-xs text-destructive">{s.failure_reason}</div>
                        )}
                        {s.applicant_id && (
                          <div className="mt-0.5 text-xs text-muted-foreground">Candidate #{s.applicant_id}</div>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        {s.status === "failed" && (
                          <Button size="sm" variant="outline" onClick={() => retry(s.id)}>
                            <RefreshCw className="mr-1 size-3.5" /> Retry
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          )
        )}
      </section>
      <Dialog open={!!shown} onOpenChange={(o) => !o && setShown(null)}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Copy this secret now</DialogTitle>
            <DialogDescription>
              It is shown once and stored only as a hash. Paste it into the form's Apps Script as the
              Script Property RG_SECRET, with RG_KEY set to {shown?.key}. Do not email it or put it in a document.
            </DialogDescription>
          </DialogHeader>
          <Input readOnly value={shown?.secret ?? ""} onFocus={(e) => e.currentTarget.select()} className="font-mono text-xs" aria-label="Shared secret" />
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                if (shown) navigator.clipboard?.writeText(shown.secret).then(() => toast.success("Copied."), () => {})
              }}
            >
              Copy
            </Button>
            <Button onClick={() => setShown(null)}>Done</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
