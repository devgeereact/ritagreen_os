import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import path from "path"

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
  plugins: [react()],
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
        // Split heavy, rarely-changing vendor libs out of the main bundle so
        // the public site doesn't pay for CMS-only charts/calendar/editor code.
        manualChunks: {
          react: ["react", "react-dom", "react-router-dom"],
          charts: ["apexcharts", "react-apexcharts"],
          calendar: [
            "@fullcalendar/daygrid",
            "@fullcalendar/timegrid",
            "@fullcalendar/interaction",
            "@fullcalendar/react",
          ],
          editor: ["@tiptap/react", "@tiptap/starter-kit"],
          pdf: ["jspdf", "jspdf-autotable"],
        },
      },
    },
  },
})
