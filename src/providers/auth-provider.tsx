import { createContext, useContext, useEffect, useState, type ReactNode } from "react"
import { api, getToken, setToken } from "@/lib/api"

// The seven roles enforced across RitaGreenOS. Server-side checks are the
// source of truth; this is used only to shape the UI.
export type Role =
  | "super_admin"
  | "admin"
  | "manager"
  | "trainer"
  | "editor"
  | "employee"
  | "client"

export type User = {
  id: number
  name: string
  email: string
  role: Role
  division: string | null
  client_id?: number | null
  worker_id?: number | null
  must_setup_2fa?: boolean
}

// Where a user lands after login / on hitting the app, by account type.
export function homeFor(user: Pick<User, "role" | "client_id" | "worker_id">): string {
  if (user.role === "client" || user.client_id) return "/portal"
  if (user.worker_id) return "/m"
  return "/app"
}

type AuthContextValue = {
  user: User | null
  loading: boolean
  // Returns { twofaRequired: true } when a 2FA code is needed to complete login.
  login: (email: string, password: string, code?: string) => Promise<{ twofaRequired: boolean; user?: User }>
  // Adopt a session obtained out-of-band (e.g. Google SSO callback).
  adoptSession: (token: string, user: User) => void
  logout: () => void
  hasRole: (...roles: Role[]) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  // On boot, if a token exists, resolve the current user.
  useEffect(() => {
    const token = getToken()
    if (!token) {
      setLoading(false)
      return
    }
    api<{ user: User }>("auth.php?action=me")
      .then((res) => setUser(res.user))
      .catch(() => {
        setToken(null)
        setUser(null)
      })
      .finally(() => setLoading(false))
  }, [])

  async function login(email: string, password: string, code?: string) {
    const res = await api<{ token?: string; user?: User; twofa_required?: boolean }>(
      "auth.php?action=login",
      { method: "POST", body: { email, password, code }, auth: false },
    )
    if (res.twofa_required) return { twofaRequired: true }
    setToken(res.token!)
    setUser(res.user!)
    return { twofaRequired: false, user: res.user! }
  }

  function adoptSession(token: string, u: User) {
    setToken(token)
    setUser(u)
  }

  function logout() {
    const token = getToken()
    if (token) {
      // Best-effort server-side token revoke; ignore failures.
      api("auth.php?action=logout", { method: "POST" }).catch(() => {})
    }
    setToken(null)
    setUser(null)
    // The service worker keeps the last worker reads (me.php, notifications.php) for offline
    // use. Drop them on sign-out so the next person on a shared device cannot read them.
    if ("caches" in window) caches.delete("worker-reads").catch(() => {})
  }

  function hasRole(...roles: Role[]) {
    return !!user && roles.includes(user.role)
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, adoptSession, logout, hasRole }}>
      {children}
    </AuthContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error("useAuth must be used within AuthProvider")
  return ctx
}
