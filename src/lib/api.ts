// Central API client for RitaGreenOS.
//
// React never touches MySQL. Every call goes to a PHP endpoint that returns
// JSON. In dev we hit the live API over HTTPS (api.php sends CORS headers);
// in production the build is served from the same domain, so the base is "".
export const API_BASE = import.meta.env.DEV ? "https://ritagreenservices.com" : ""

const TOKEN_KEY = "rgos_token"

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  status: number
  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

type ApiOptions = {
  method?: "GET" | "POST" | "PUT" | "DELETE"
  body?: unknown
  // endpoint file, e.g. "api.php", "auth.php", "clients.php"
  auth?: boolean
}

// api("clients.php", { method: "POST", body: {...} })
export async function api<T = unknown>(endpoint: string, opts: ApiOptions = {}): Promise<T> {
  const { method = "GET", body, auth = true } = opts
  const headers: Record<string, string> = { Accept: "application/json" }
  if (body !== undefined) headers["Content-Type"] = "application/json"
  if (auth) {
    const token = getToken()
    if (token) headers["Authorization"] = `Bearer ${token}`
  }

  let res: Response
  try {
    res = await fetch(`${API_BASE}/${endpoint}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError("Cannot reach the server. Check your connection and try again.", 0)
  }

  const text = await res.text()
  let data: unknown = null
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      throw new ApiError("The server returned an unexpected response.", res.status)
    }
  }

  if (!res.ok) {
    const msg =
      (data && typeof data === "object" && "error" in data && typeof (data as { error: unknown }).error === "string"
        ? (data as { error: string }).error
        : null) || `Request failed (${res.status}).`
    throw new ApiError(msg, res.status)
  }

  return data as T
}

// AI draft helper. Routes every prompt server-side through ai.php so the
// OpenRouter key never reaches the browser.
export async function aiDraft(prompt: string, system?: string): Promise<string> {
  const res = await api<{ reply: string }>("ai.php", { method: "POST", body: { prompt, system } })
  return res.reply
}
