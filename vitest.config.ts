import { defineConfig } from "vitest/config"
import path from "path"

// Unit tests for pure frontend logic. Deliberately node-environment: nothing
// here touches the DOM, and adding jsdom for tests that do not need it would
// slow every run. Server-side logic is tested separately by server/tests/run.php.
export default defineConfig({
  resolve: {
    alias: { "@": path.resolve(__dirname, "./src") },
  },
  test: {
    environment: "node",
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
  },
})
