import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import { VitePWA } from "vite-plugin-pwa"
import path from "path"
import { buildSitemapXml } from "./src/lib/public-routes"

// Where the dev server sends *.php. Local by default — see the proxy comment below.
const API_TARGET = process.env.RG_API_TARGET ?? "http://127.0.0.1:8122"
const USING_PRODUCTION = /ritagreenservices\.com/.test(API_TARGET)

if (USING_PRODUCTION) {
  console.warn(
    "\n  WARNING: the dev server is proxying to PRODUCTION (" + API_TARGET + ")." +
      "\n  Reads and writes will hit live workforce data. Unset RG_API_TARGET to use the local API.\n",
  )
}

export default defineConfig({
  base: "/",
  plugins: [
    react(),
    // dist/sitemap.xml, written at build time from the public route table in
    // src/lib/public-routes.ts, the same table the router's redirects read. It
    // replaced a hand-kept public/sitemap.xml that still listed retired pages.
    // Static pages only: published posts live in the database, which the build
    // in CI cannot reach (docs/ARCHITECTURE.md).
    {
      name: "rg-sitemap",
      apply: "build",
      generateBundle() {
        this.emitFile({ type: "asset", fileName: "sitemap.xml", source: buildSitemapXml() })
      },
    },
    // Replaces the hand-written public/sw.js, which precached four fixed paths
    // and had no idea about hashed asset names. Assets were only cached
    // opportunistically after a first fetch, so offline broke after every
    // deploy -- the one moment it most needed to work.
    VitePWA({
      registerType: "prompt", // never swap the app out under someone mid-task
      // og.png is deliberately absent: includeAssets OVERRIDES globIgnores, so
      // listing it here quietly precached the social-share image the app never
      // displays, while the globIgnores entry below looked like it excluded it.
      includeAssets: ["favicon.svg", "apple-touch-icon.png"],
      manifest: {
        name: "RitaGreen Worker",
        short_name: "RitaGreen",
        description: "Shifts, clock-in, compliance and messages for RitaGreen workers.",
        start_url: "/m",
        scope: "/",
        display: "standalone",
        background_color: "#F7F6F1",
        theme_color: "#33512D",
        icons: [
          { src: "/icon-192.png", sizes: "192x192", type: "image/png" },
          { src: "/icon-512.png", sizes: "512x512", type: "image/png" },
          // Maskable is a separate entry on purpose: Android crops it to the
          // launcher's shape, so it is drawn inset and bleeds to the edges.
          { src: "/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
        ],
      },
      workbox: {
        // The build hash goes in the precache manifest, so a deploy invalidates
        // exactly what changed and offline keeps working.
        globPatterns: ["**/*.{js,css,html,svg,png,woff2}"],
        // The installable app is the WORKER app, starting at /m. Precaching the
        // office bundle would make a field worker download charts, a calendar
        // and a PDF engine they will never open -- about 1.5 MB of the total.
        // These stay available online and are simply not part of the install.
        //
        // These patterns must be checked against `ls dist/assets` after any
        // change to chunking. They previously named chunks that manualChunks
        // created -- `charts-*`, `calendar-*` -- and when those manual entries
        // were removed the patterns silently matched nothing (except, absurdly,
        // `calendar-check-*.js`, a 0.3 kB lucide icon), so 700 kB of office code
        // quietly re-entered the worker's install. A glob that matches nothing
        // fails silently, which is exactly how this drifted.
        //
        // Excluding these is only safe because they are genuine async chunks,
        // reached from lazily-imported CMS pages. Do not exclude anything the
        // entry preloads: offline, the module graph waits for it forever and the
        // app renders a blank page on every route.
        globIgnores: [
          "**/react-apexcharts.esm-*.js", // charts — Dashboard, Analytics, Insights
          "**/Scheduling-*.js", // the office rota; nothing a worker opens offline
          "**/pdf-*.js", // jspdf + autotable
          "**/html2canvas*.js", // jspdf dependency
          "**/index.es-*.js", // jspdf dependency
          "**/purify.es-*.js", // jspdf dependency
          "**/og.png", // social share image, never needed by the app
          // Latin Extended font subsets. Same-origin and cached normally by the
          // browser, but ~430 kB that most visits never request — the browser
          // only fetches a subset when a glyph in its unicode-range appears.
          // Offline, a name with an accent falls back to the system font rather
          // than the install being half a megabyte heavier for everyone.
          "**/*-latin-ext.woff2",
          // Source Serif 4 is for website headlines only. The worker app never
          // sets a serif, so it is not part of the install.
          "**/source-serif-4-*.woff2",
        ],
        maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
        navigateFallback: "/index.html",
        // The PHP API is never cached. Compliance data going stale in a cache
        // would mean showing somebody an eligibility answer that is no longer
        // true, which is worse than showing them nothing.
        navigateFallbackDenylist: [/\.php/],
        runtimeCaching: [
          {
            // Read-only worker data, network-first: fresh when online, and the
            // last known answer when not. Writes are never served from here.
            // Payslips are excluded: pay data must not outlive the session on the device.
            urlPattern: /\/(?:me\.php(?![^#]*[?&]action=payslip)|notifications\.php)/,
            handler: "NetworkFirst",
            method: "GET",
            options: {
              cacheName: "worker-reads",
              networkTimeoutSeconds: 5,
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 12 },
            },
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  // Project block 12 in the workspace port scheme (see ~/.claude/CLAUDE.md, "Dev-server ports").
  // strictPort so a clash fails loudly instead of drifting to a random port.
  // Previously unpinned, so this fell back to Vite's shared 5173.
  server: {
    port: 5122,
    strictPort: true,
    // Forward PHP API calls so the browser sees them as same-origin.
    //
    // The target defaults to the LOCAL PHP server (npm run dev:api). It used to
    // default to https://ritagreenservices.com, which meant every developer read
    // and wrote the live workforce database — including while testing a
    // migration. Pointing at production is now a deliberate act:
    //
    //     RG_API_TARGET=https://ritagreenservices.com npm run dev
    //
    // See docs/RULES.md rule 13.
    proxy: {
      "^/.*\\.php": {
        target: API_TARGET,
        changeOrigin: true,
        secure: true,
        configure: (proxy) => {
          proxy.on("error", (err) => {
            if (!USING_PRODUCTION) {
              console.error(
                `\n  API request failed against ${API_TARGET}: ${err.message}` +
                  "\n  Is the local PHP server running?  npm run dev:api" +
                  "\n  Setup: docs/ARCHITECTURE.md, section 10.\n",
              )
            }
          })
        },
      },
    },
  },
  preview: {
    port: 5122,
    strictPort: true,
  },
  build: {
    rollupOptions: {
      output: {
        // React only.
        //
        // charts, calendar and pdf used to be listed here too, to "stop the
        // public site paying for CMS-only code". They did the opposite. Naming a
        // manual chunk promotes it into the entry's dependency graph, so the
        // build emitted `<link rel="modulepreload" href="/assets/pdf-*.js">` in
        // index.html and every visitor to the marketing home page preloaded a
        // 417 kB PDF library reachable only from four lazy CMS pages.
        //
        // Worse, it broke offline outright. The workbox `globIgnores` below
        // deliberately keeps those same chunks OUT of the precache, so offline
        // the preload for pdf-*.js hung unresolved, the entry module graph never
        // settled, and the app rendered a blank page -- on every route,
        // including the worker app the PWA exists for. Reproduced offline
        // against both this build and the pre-audit one.
        //
        // Without the manual entries Rollup still splits them, because they are
        // only reachable through lazily-imported pages -- but as genuine async
        // chunks, fetched when a page needs them rather than preloaded by the
        // entry. React stays because it IS needed immediately, so preloading it
        // is correct.
        manualChunks: {
          react: ["react", "react-dom", "react-router-dom"],
        },
      },
    },
  },
})
