import { useState, type FormEvent } from "react"
import { ApiError } from "@/lib/api"
import { COMPANY_DETAILS } from "@/lib/company"

export type FieldErrors<V> = Partial<Record<keyof V, string>>
export type FormStatus = "idle" | "sending" | "sent"

export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * What a visitor is told when sending fails.
 *
 * A refusal the visitor can act on (a field the server rejected, too many
 * attempts) is shown as the server worded it. Anything else (no connection, a
 * server fault, an endpoint not yet uploaded) gets one plain sentence and the
 * phone number, never the developer-facing text api() builds for the office app.
 */
export function failureMessage(err: unknown): string {
  if (err instanceof ApiError && (err.status === 400 || err.status === 422 || err.status === 429)) {
    return err.message
  }
  return `We could not send this just now. Your details are still here, so please try again in a minute, or call us on ${COMPANY_DETAILS.phone.display}.`
}

/**
 * State for one website enquiry form.
 *
 * Values are controlled and are only cleared by an explicit "send another", so
 * a failed send never loses what was typed. Validation runs on submit, marks
 * each field it rejects and moves focus to the first one.
 */
export function useEnquiryForm<V extends Record<string, string | boolean>>(opts: {
  initial: V
  validate: (values: V) => FieldErrors<V>
  send: (values: V) => Promise<unknown>
}) {
  const [values, setValues] = useState<V>(opts.initial)
  const [errors, setErrors] = useState<FieldErrors<V>>({})
  const [status, setStatus] = useState<FormStatus>("idle")
  const [failure, setFailure] = useState<string | null>(null)

  function set<K extends keyof V>(key: K, value: V[K]) {
    setValues((prev) => ({ ...prev, [key]: value }))
    setErrors((prev) => (prev[key] ? { ...prev, [key]: undefined } : prev))
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const formEl = e.currentTarget
    if (status === "sending") return
    setFailure(null)
    const found = opts.validate(values)
    setErrors(found)
    if (Object.values(found).some(Boolean)) {
      // After the render that sets aria-invalid.
      window.setTimeout(() => formEl.querySelector<HTMLElement>("[aria-invalid='true']")?.focus(), 0)
      return
    }
    setStatus("sending")
    try {
      await opts.send(values)
      setStatus("sent")
    } catch (err) {
      setStatus("idle")
      setFailure(failureMessage(err))
    }
  }

  function reset() {
    setValues(opts.initial)
    setErrors({})
    setFailure(null)
    setStatus("idle")
  }

  return { values, set, errors, status, failure, onSubmit, reset }
}
