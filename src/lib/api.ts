// Central API client for RitaGreenOS.
//
// React never touches MySQL. Every call goes to a PHP endpoint that returns
// JSON. In dev we hit the live API over HTTPS (api.php sends CORS headers);
// in production the build is served from the same domain, so the base is "".
import { saveAs } from "file-saver"

// Both dev and prod use a relative base. In dev, Vite proxies /*.php to the
// live server (see vite.config.ts) so the browser treats calls as same-origin
// and CORS never applies. In prod the build is served from the same domain.
export const API_BASE = ""

const TOKEN_KEY = "rgos_token"
const TOKEN_ISSUED_KEY = "rgos_token_issued"

// The server expires a token after 30 days (see issue_token in auth.php). The
// browser had no matching rule, so a token sat in local storage indefinitely
// and was only discarded once a call came back 401. On a shared device that is
// a valid-looking credential lying around long after anyone stopped using it.
const TOKEN_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000

export function getToken(): string | null {
  const token = localStorage.getItem(TOKEN_KEY)
  if (!token) return null
  const issued = Number(localStorage.getItem(TOKEN_ISSUED_KEY) ?? 0)
  // An unstamped token predates this and is trusted until the server says
  // otherwise; a stamped one that has run out is dropped here.
  if (issued > 0 && Date.now() - issued > TOKEN_MAX_AGE_MS) {
    setToken(null)
    return null
  }
  return token
}

export function setToken(token: string | null) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
    localStorage.setItem(TOKEN_ISSUED_KEY, String(Date.now()))
  } else {
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(TOKEN_ISSUED_KEY)
  }
}

/**
 * Drop the service worker's cached API responses.
 *
 * The worker app caches me.php and notifications.php for 12 hours so it still
 * shows a worker their shifts and compliance status with no signal. Signing out
 * cleared the token and left that cache alone, so the next person to open the
 * app on a shared phone could be shown the previous worker's compliance record.
 * Best-effort: the Cache API is unavailable in some contexts and a failure here
 * must not stop somebody signing out.
 */
export async function purgeCachedApiReads(): Promise<void> {
  try {
    if (typeof caches === "undefined") return
    await caches.delete("worker-reads")
  } catch {
    /* ignore */
  }
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
      // HTML instead of JSON = the endpoint is missing on the server and the
      // SPA rewrite served index.html. Almost always means server/*.php has
      // not been uploaded to the document root yet.
      const msg = text.trimStart().startsWith("<")
        ? `The ${endpoint.split("?")[0]} endpoint is not on the server yet. Upload the server/ PHP files to the document root.`
        : "The server returned an unexpected response."
      throw new ApiError(msg, res.status)
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

// Multipart upload. Browser sets the Content-Type boundary, so we don't.
export async function uploadFile<T = unknown>(endpoint: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" }
  const token = getToken()
  if (token) headers["Authorization"] = `Bearer ${token}`

  let res: Response
  try {
    res = await fetch(`${API_BASE}/${endpoint}`, { method: "POST", headers, body: form })
  } catch {
    throw new ApiError("Cannot reach the server.", 0)
  }
  const text = await res.text()
  let data: unknown = null
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      // Non-JSON (e.g. an HTML error page or a PHP fatal) — surface a friendly
      // message instead of letting a raw SyntaxError bubble up.
      const msg = text.trimStart().startsWith("<")
        ? `The ${endpoint.split("?")[0]} endpoint is not on the server yet. Upload the server/ PHP files to the document root.`
        : "The server returned an unexpected response."
      throw new ApiError(msg, res.status)
    }
  }
  if (!res.ok) {
    const msg =
      data && typeof data === "object" && "error" in data && typeof (data as { error: unknown }).error === "string"
        ? (data as { error: string }).error
        : `Upload failed (${res.status}).`
    throw new ApiError(msg, res.status)
  }
  return data as T
}

// Authenticated download — fetch with the token, then save the blob.
export async function downloadFile(endpoint: string, filename: string): Promise<void> {
  const headers: Record<string, string> = {}
  const token = getToken()
  if (token) headers["Authorization"] = `Bearer ${token}`
  const res = await fetch(`${API_BASE}/${endpoint}`, { headers })
  if (!res.ok) throw new ApiError(`Download failed (${res.status}).`, res.status)
  const blob = await res.blob()
  saveAs(blob, filename)
}

// AI draft helper. Routes every prompt server-side through ai.php so the
// OpenRouter key never reaches the browser.
export async function aiDraft(prompt: string, system?: string): Promise<string> {
  const res = await api<{ reply: string }>("ai.php", { method: "POST", body: { prompt, system } })
  return res.reply
}
