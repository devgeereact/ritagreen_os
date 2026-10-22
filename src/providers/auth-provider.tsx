import { createContext, useContext, useEffect, useState, type ReactNode } from "react"
import { api, getToken, setToken, purgeCachedApiReads } from "@/lib/api"

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
  // Whether we are resolving a session is knowable at the first render: either a
  // token is stored or it is not. Starting at `true` and immediately setting it
  // false inside the effect cost every signed-out visitor a render of the
  // loading state, and is the cascading-render pattern react-hooks warns about.
  const [loading, setLoading] = useState(() => getToken() !== null)

  // On boot, if a token exists, resolve the current user.
  useEffect(() => {
    if (!getToken()) return
    api<{ user: User }>("auth.php?action=me")
      .then((res) => setUser(res.user))
      .catch(() => {
        setToken(null)
        setUser(null)
        void purgeCachedApiReads()
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
    // Signing out has to take the cached reads with it, or the next person to
    // pick up the device still sees the last worker's shifts and compliance.
    void purgeCachedApiReads()
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
