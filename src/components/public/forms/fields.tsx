import { useEffect, useRef, type ReactNode } from "react"
import { Link } from "react-router-dom"
import { AlertCircle, CheckCircle2 } from "lucide-react"
import { cn } from "@/lib/utils"
import { siteButton } from "@/components/public/site-button"
import type { Option } from "@/components/public/forms/options"

// Form fields for the website, from the 2026 mock: a visible label above every
// field, 44px controls, a 1px border that meets 3:1, a 2px green focus ring, and
// an error written under the field it belongs to and tied to it with
// aria-describedby. Optional fields say "(optional)"; required ones carry
// aria-required, so nothing depends on a red asterisk.

const control =
  "w-full rounded-lg border border-input bg-card px-3 text-base text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline focus:outline-2 focus:outline-offset-1 focus:outline-primary aria-[invalid=true]:border-destructive"

type Common = {
  id: string
  label: string
  optional?: boolean
  hint?: string
  error?: string
  className?: string
}

function describedBy(id: string, hint?: string, error?: string): string | undefined {
  const ids = [hint ? `${id}-hint` : "", error ? `${id}-error` : ""].filter(Boolean)
  return ids.length ? ids.join(" ") : undefined
}

function FieldShell({ id, label, optional, hint, error, className, children }: Common & { children: ReactNode }) {
  return (
    <div className={cn("grid content-start gap-1.5", className)}>
      <label htmlFor={id} className="text-[15px] font-semibold text-foreground">
        {label}
        {optional && <span className="font-normal text-muted-foreground"> (optional)</span>}
      </label>
      {/* The hint sits under the control, so fields side by side line up. */}
      {children}
      {hint && (
        <p id={`${id}-hint`} className="text-sm text-muted-foreground">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="flex items-start gap-1.5 text-sm font-medium text-destructive">
          <AlertCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
          {error}
        </p>
      )}
    </div>
  )
}

export function TextField({
  value,
  onChange,
  type = "text",
  autoComplete,
  maxLength,
  inputMode,
  min,
  max,
  ...common
}: Common & {
  value: string
  onChange: (v: string) => void
  type?: "text" | "email" | "tel" | "date" | "datetime-local" | "number"
  autoComplete?: string
  maxLength?: number
  inputMode?: "text" | "email" | "tel" | "numeric"
  min?: string
  max?: string
}) {
  const { id, hint, error, optional } = common
  return (
    <FieldShell {...common}>
      <input
        id={id}
        name={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        autoComplete={autoComplete}
        maxLength={maxLength}
        inputMode={inputMode}
        min={min}
        max={max}
        aria-required={!optional || undefined}
        aria-invalid={!!error}
        aria-describedby={describedBy(id, hint, error)}
        className={cn(control, "h-11")}
      />
    </FieldShell>
  )
}

export function TextAreaField({
  value,
  onChange,
  rows = 4,
  maxLength,
  placeholder,
  ...common
}: Common & { value: string; onChange: (v: string) => void; rows?: number; maxLength?: number; placeholder?: string }) {
  const { id, hint, error, optional } = common
  return (
    <FieldShell {...common}>
      <textarea
        id={id}
        name={id}
        rows={rows}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        maxLength={maxLength}
        placeholder={placeholder}
        aria-required={!optional || undefined}
        aria-invalid={!!error}
        aria-describedby={describedBy(id, hint, error)}
        className={cn(control, "py-2.5 leading-relaxed")}
      />
    </FieldShell>
  )
}

/** A native select: it works with every screen reader and phone keyboard. */
export function SelectField({
  value,
  onChange,
  options,
  placeholder = "Choose one",
  ...common
}: Common & { value: string; onChange: (v: string) => void; options: Option[]; placeholder?: string }) {
  const { id, hint, error, optional } = common
  return (
    <FieldShell {...common}>
      <div className="relative">
        <select
          id={id}
          name={id}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          aria-required={!optional || undefined}
          aria-invalid={!!error}
          aria-describedby={describedBy(id, hint, error)}
          className={cn(control, "h-11 appearance-none pr-10")}
        >
          <option value="">{placeholder}</option>
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <span
          aria-hidden="true"
          className="pointer-events-none absolute right-4 top-1/2 size-2 -translate-y-3/4 rotate-45 border-b-2 border-r-2 border-muted-foreground"
        />
      </div>
    </FieldShell>
  )
}

export function CheckField({
  id,
  checked,
  onChange,
  error,
  required,
  children,
}: {
  id: string
  checked: boolean
  onChange: (v: boolean) => void
  error?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <div className="grid gap-1.5">
      <div className="flex items-start gap-3">
        <input
          id={id}
          name={id}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          aria-required={required || undefined}
          aria-invalid={!!error}
          aria-describedby={error ? `${id}-error` : undefined}
          className="mt-0.5 size-5 shrink-0 rounded border-input accent-[#33512D]"
        />
        <label htmlFor={id} className="text-[15px] leading-snug text-foreground">
          {children}
        </label>
      </div>
      {error && (
        <p id={`${id}-error`} className="flex items-start gap-1.5 text-sm font-medium text-destructive">
          <AlertCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
          {error}
        </p>
      )}
    </div>
  )
}

/** The failure line above the send button. Announced, and what was typed stays. */
export function FormFailure({ message }: { message: string | null }) {
  if (!message) return null
  return (
    <div role="alert" className="flex items-start gap-3 rounded-lg border border-destructive/40 bg-destructive-soft p-4 text-[15px] text-destructive">
      <AlertCircle aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
      <p>{message}</p>
    </div>
  )
}

/** The panel that replaces a form once it has been sent. Focus moves to it. */
export function FormSuccess({
  title,
  children,
  onReset,
  resetLabel = "Send another",
}: {
  title: string
  children: ReactNode
  onReset: () => void
  resetLabel?: string
}) {
  const ref = useRef<HTMLHeadingElement>(null)
  useEffect(() => {
    ref.current?.focus()
  }, [])
  return (
    <div role="status" className="rounded-lg border border-success/30 bg-success-soft p-6">
      <div className="flex items-start gap-3">
        <CheckCircle2 aria-hidden="true" className="mt-1 size-6 shrink-0 text-success" />
        <div>
          <h3 ref={ref} tabIndex={-1} className="text-xl font-semibold text-foreground focus:outline-none">
            {title}
          </h3>
          <div className="mt-2 text-foreground">{children}</div>
          <button type="button" onClick={onReset} className={cn(siteButton({ variant: "secondary", size: "sm" }), "mt-5")}>
            {resetLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

/** The line every form carries under its send button. */
export function PrivacyNote({ children }: { children?: ReactNode }) {
  return (
    <p className="text-sm text-muted-foreground">
      {children ?? "We use these details to answer your enquiry."} Read our{" "}
      {/* A router link, so reading the notice does not lose what was typed. */}
      <Link to="/privacy" className="font-medium text-primary underline underline-offset-4">
        privacy notice
      </Link>{" "}
      to see how we look after them.
    </p>
  )
}

export function SubmitButton({ sending, children }: { sending: boolean; children: ReactNode }) {
  return (
    <button type="submit" disabled={sending} aria-disabled={sending} className={cn(siteButton(), "justify-self-start")}>
      {sending ? "Sending..." : children}
    </button>
  )
}
