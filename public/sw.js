// RitaGreen worker PWA service worker — minimal offline shell.
// Network-first for navigations (so updates land), cache fallback when offline.
// Stale-while-revalidate for built static assets.
const CACHE = "rgos-v1"
const SHELL = ["/", "/m", "/favicon.svg", "/manifest.webmanifest"]

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()))
})

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

self.addEventListener("fetch", (event) => {
  const { request } = event
  if (request.method !== "GET") return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return // never cache API/cross-origin

  // Navigations: network-first, fall back to cached app shell when offline.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(() => caches.match(request).then((r) => r || caches.match("/m") || caches.match("/"))),
    )
    return
  }

  // Static assets: stale-while-revalidate.
  if (/\.(js|css|svg|png|jpg|woff2?)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const network = fetch(request).then((res) => {
          const copy = res.clone()
          caches.open(CACHE).then((c) => c.put(request, copy))
          return res
        }).catch(() => cached)
        return cached || network
      }),
    )
  }
})
