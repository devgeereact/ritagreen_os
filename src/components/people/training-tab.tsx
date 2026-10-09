import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { usePostAction } from "@/hooks/use-post-action"
import { dateOnly, isRestricted } from "@/lib/people"
import type { PersonProfile, RequirementChip } from "@/lib/types"
import { Fact, FileLink, RestrictedBlock, Section, StatusChip, UploadButton } from "@/components/people/shared"

/** An expiry as a chip. Training with no expiry date simply does not lapse. */
function ExpiryChip({ rag, days }: { rag: string | null; days: number | null }) {
  if (rag === null || rag === "unknown") return <span className="text-xs text-muted-foreground">Does not expire</span>
  const chip: RequirementChip = rag === "valid" ? "valid" : rag === "expiring" ? "due_soon" : "expired"
  const label = rag === "valid" ? "Valid" : rag === "expiring" ? `Renew in ${days ?? 0} days` : "Expired"
  return <StatusChip chip={chip} label={label} />
}

/**
 * Training records: what was completed, with whom, when it runs out and the
 * certificate. Not a learning platform: no lessons, quizzes or progress.
 */
export function TrainingTab({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  const training = profile.training
  if (isRestricted(training)) return <RestrictedBlock what="training records" />

  return (
    <div className="space-y-4">
      <Section title="Training and certificates" description="Courses completed, with renewal dates.">
        {training.records.length === 0 ? (
          <p className="text-sm text-muted-foreground">No training recorded yet.</p>
        ) : (
          <ul className="divide-y divide-border">
            {training.records.map((r) => (
              <li key={r.id} className="grid gap-2 py-3 sm:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))] sm:items-center">
                <div className="min-w-0">
                  <p className="font-medium">{r.course ?? "Course not named"}</p>
                  <p className="text-xs text-muted-foreground">{r.provider ?? "Provider not recorded"}{r.status !== "completed" ? `, ${r.status.replace("_", " ")}` : ""}</p>
                </div>
                <Fact label="Completed">{r.completed_on ? dateOnly(r.completed_on) : "Not yet"}</Fact>
                <Fact label="Renewal">{r.expires_on ? dateOnly(r.expires_on) : "None"} <ExpiryChip rag={r.rag} days={r.days} /></Fact>
                <Fact label="Certificate">
                  {r.evidence_file_id ? <FileLink id={r.evidence_file_id} name={r.evidence_file_name} />
                    : r.certificate_public_id ? <span className="tabular-nums">{r.certificate_public_id}</span> : "None on file"}
                </Fact>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section title="Credentials held" description="Certifications on the worker record, such as first aid or a licence.">
        {training.certifications.length === 0 ? (
          <p className="text-sm text-muted-foreground">None recorded.</p>
        ) : (
          <ul className="divide-y divide-border">
            {training.certifications.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span><span className="font-medium">{c.type}</span>{c.number && <span className="text-muted-foreground">, {c.number}</span>}</span>
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">{c.expiry ? dateOnly(c.expiry) : "No expiry"}</span>
                  <ExpiryChip rag={c.rag} days={c.days} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      {profile.can.manage && (
        <AddTrainingRecord personId={profile.person.id} providers={training.providers} onChanged={onChanged} />
      )}
    </div>
  )
}

function AddTrainingRecord({ personId, providers, onChanged }: {
  personId: number
  providers: { id: number; name: string }[]
  onChanged: () => void
}) {
  const { run, busy } = usePostAction(onChanged)
  const [provider, setProvider] = useState("")
  const [course, setCourse] = useState("")
  const [completed, setCompleted] = useState("")
  const [expires, setExpires] = useState("")
  const [fileId, setFileId] = useState<number | null>(null)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    const ok = await run(`people.php?action=training&id=${personId}`, {
      provider_id: provider || null, course, completed_on: completed, expires_on: expires, evidence_file_id: fileId,
    }, "Training recorded.")
    if (ok) {
      setCourse(""); setCompleted(""); setExpires(""); setFileId(null); setProvider("")
    }
  }

  return (
    <Section title="Add a training record" description="Training done here or elsewhere, with its certificate.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="tr-provider">Provider</Label>
          <Select value={provider} onValueChange={setProvider}>
            <SelectTrigger id="tr-provider"><SelectValue placeholder="Choose a provider" /></SelectTrigger>
            <SelectContent>
              {providers.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="tr-course">Course</Label>
          <Input id="tr-course" required maxLength={190} value={course} onChange={(e) => setCourse(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="tr-completed">Completed on</Label>
          <Input id="tr-completed" type="date" required value={completed} onChange={(e) => setCompleted(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="tr-expires">Expires on</Label>
          <Input id="tr-expires" type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
        </div>
        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
          <UploadButton personId={personId} category="certificate" label={fileId ? "Replace certificate" : "Upload certificate"}
            onUploaded={(id) => setFileId(id)} />
          {fileId && <FileLink id={fileId} name="Uploaded certificate" />}
          <Button type="submit" className="ml-auto" disabled={busy || course.trim() === "" || completed === ""}>
            {busy ? "Saving…" : "Add record"}
          </Button>
        </div>
      </form>
    </Section>
  )
}
