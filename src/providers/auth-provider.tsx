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
}

type AuthContextValue = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
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

  async function login(email: string, password: string) {
    const res = await api<{ token: string; user: User }>("auth.php?action=login", {
      method: "POST",
      body: { email, password },
      auth: false,
    })
    setToken(res.token)
    setUser(res.user)
  }

  function logout() {
    const token = getToken()
    if (token) {
      // Best-effort server-side token revoke; ignore failures.
      api("auth.php?action=logout", { method: "POST" }).catch(() => {})
    }
    setToken(null)
    setUser(null)
  }

  function hasRole(...roles: Role[]) {
    return !!user && roles.includes(user.role)
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasRole }}>
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
