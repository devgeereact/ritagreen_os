import type { ReactNode } from "react"
import ClerkSession from "@/auth/ClerkSession"
import ClerkGate from "@/auth/ClerkGate"

/** Clerk's session plus the user lookup. Its own file so RequireAuth can load it lazily. */
export default function ClerkArea({ children }: { children: ReactNode }) {
  return (
    <ClerkSession>
      <ClerkGate>{children}</ClerkGate>
    </ClerkSession>
  )
}
