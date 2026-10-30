import { useCallback, useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import { Lock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useAuth } from "@/providers/auth-provider"
import { api, ApiError } from "@/lib/api"
import { clerkEnabled } from "@/lib/clerk"

const IDLE_MS = 15 * 60 * 1000 // lock after 15 minutes idle

// Idle auto-lock for the CMS. After inactivity the screen locks and the user
// must re-enter their password (verified server-side) to continue.
// Under Clerk there is no password to re-enter; the Clerk session ends like any other.
export function LockScreen() {
  return clerkEnabled ? null : <PasswordLock />
}

function PasswordLock() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [locked, setLocked] = useState(false)
  const [password, setPassword] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const timer = useRef<number | undefined>(undefined)

  const reset = useCallback(() => {
    window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => setLocked(true), IDLE_MS)
  }, [])

  useEffect(() => {
    if (locked) return
    const events = ["mousemove", "mousedown", "keydown", "touchstart", "scroll"]
    const onActivity = () => reset()
    events.forEach((e) => window.addEventListener(e, onActivity, { passive: true }))
    reset()
    return () => {
      window.clearTimeout(timer.current)
      events.forEach((e) => window.removeEventListener(e, onActivity))
    }
  }, [locked, reset])

  async function unlock(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api("auth.php?action=unlock", { method: "POST", body: { password } })
      setPassword("")
      setLocked(false)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Incorrect password.")
    } finally {
      setBusy(false)
    }
  }

  if (!locked) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-background/95 backdrop-blur">
      <div className="w-full max-w-sm rounded-xl border bg-card p-6 shadow-xl">
        <div className="flex flex-col items-center text-center">
          <span className="grid size-12 place-items-center rounded-full bg-primary/10 text-primary"><Lock className="size-6" /></span>
          <h2 className="mt-3 text-lg font-bold">Screen locked</h2>
          <p className="mt-1 text-sm text-muted-foreground">Signed in as {user?.name}. Enter your password to continue.</p>
        </div>
        <form onSubmit={unlock} className="mt-5 grid gap-3">
          {error && <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
          <div className="grid gap-2">
            <Label htmlFor="unlock-pw">Password</Label>
            <Input id="unlock-pw" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus required />
          </div>
          <Button type="submit" disabled={busy}>{busy ? "Unlocking…" : "Unlock"}</Button>
          <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => { logout(); navigate("/login", { replace: true }) }}>
            Not you? Sign out
          </button>
        </form>
      </div>
    </div>
  )
}
