import { useRef, useState, type ReactNode } from "react"
import { CheckCircle2, Circle, CircleAlert, Clock, Download, Lock, Upload, XCircle } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { downloadFile, uploadFile, ApiError } from "@/lib/api"
import { cn } from "@/lib/utils"
import { CHIP_ICON_TONE, CHIP_TONE, SERVICE_STATUS_LABEL, SERVICE_STATUS_TONE, serviceName } from "@/lib/people"
import type { RequirementChip, ServiceId, ServiceStatus } from "@/lib/types"

const CHIP_ICON = {
  valid: CheckCircle2,
  due_soon: Clock,
  expired: XCircle,
  awaiting_review: Circle,
  missing: CircleAlert,
} as const

/** A requirement's status: a symbol and a word, never colour alone. */
export function StatusChip({ chip, label }: { chip: RequirementChip; label: string }) {
  const Icon = CHIP_ICON[chip]
  return (
    <span className={cn("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium", CHIP_TONE[chip])}>
      <Icon className={cn("size-3.5", CHIP_ICON_TONE[chip])} aria-hidden />
      {label}
    </span>
  )
}

/** A service tag with the person's status in it: "Security · Onboarding". */
export function ServiceTag({ service, status }: { service: ServiceId; status: ServiceStatus }) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium", SERVICE_STATUS_TONE[status])}>
      <span className="font-semibold">{serviceName(service)}</span>
      <span aria-hidden>·</span>
      <span>{SERVICE_STATUS_LABEL[status]}</span>
    </span>
  )
}

/** What a section looks like to somebody whose zone does not cover it. */
export function RestrictedBlock({ what }: { what: string }) {
  return (
    <div className="flex items-start gap-3 rounded-lg border border-dashed border-border bg-muted/40 p-4 text-sm">
      <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
      <p>
        <span className="font-medium">Restricted.</span>{" "}
        <span className="text-muted-foreground">
          Your account does not have access to {what}. Ask the owner if you need it.
        </span>
      </p>
    </div>
  )
}

export function Section({ title, action, children, description }: {
  title: string
  description?: string
  action?: ReactNode
  children: ReactNode
}) {
  return (
    <section className="space-y-3 rounded-lg border border-border bg-card p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold">{title}</h2>
          {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

/** A label and a value, stacked on a phone and side by side on a wider screen. */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 break-words text-sm">{children}</dd>
    </div>
  )
}

/** A stored document, downloaded through files.php behind the login. */
export function FileLink({ id, name }: { id: number | null; name: string | null }) {
  if (!id) return <span className="text-muted-foreground">No file</span>
  const label = name ?? `Document #${id}`
  return (
    <button
      type="button"
      className="inline-flex max-w-full items-center gap-1 text-left text-sm text-primary underline-offset-2 hover:underline"
      onClick={() =>
        downloadFile(`files.php?action=download&id=${id}`, label).catch((e: unknown) =>
          toast.error(e instanceof Error ? e.message : "Could not download that file."),
        )
      }
    >
      <Download className="size-3.5 shrink-0" aria-hidden />
      <span className="truncate">{label}</span>
    </button>
  )
}

/**
 * Upload a document filed against a person, then hand its id to the caller.
 *
 * The file goes to files.php with the person's id, so it can be found from the
 * profile and refused if somebody tries to attach it to anybody else.
 */
export function UploadButton({ personId, category, label, onUploaded, disabled, size = "sm", variant = "outline" }: {
  personId: number
  category: "certificate" | "id" | "contract" | "other"
  label: string
  onUploaded: (fileId: number) => void | Promise<void>
  disabled?: boolean
  size?: "sm" | "default"
  variant?: "outline" | "default" | "secondary"
}) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)

  async function onChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    e.target.value = ""
    if (!file) return
    setBusy(true)
    try {
      const form = new FormData()
      form.append("file", file)
      form.append("category", category)
      form.append("person_id", String(personId))
      const res = await uploadFile<{ file: { id: number } }>("files.php", form)
      await onUploaded(res.file.id)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not upload that file.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <input ref={input} type="file" className="sr-only" onChange={onChange} tabIndex={-1} aria-hidden />
      <Button type="button" size={size} variant={variant} disabled={disabled || busy} onClick={() => input.current?.click()}>
        <Upload className="mr-1 size-4" aria-hidden />
        {busy ? "Uploading…" : label}
      </Button>
    </>
  )
}
