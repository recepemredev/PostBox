import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
  // tsconfig.json sets "jsx": "preserve" for Next's own SWC compiler to
  // transform; esbuild (Vitest's own transform) honours that same setting
  // and otherwise leaves JSX untransformed, which is the "React is not
  // defined" this overrides — SWC's build path is untouched.
  esbuild: {
    jsx: "automatic",
  },
  // No test asserts computed style, only rendered text, and Tailwind v4's
  // PostCSS plugin (postcss.config.mjs) is not a shape Vite's own CSS
  // pipeline understands outside a Next.js build — an empty plugin list
  // here stops Vite from auto-loading that file at all.
  css: {
    postcss: { plugins: [] },
  },
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "src"),
    },
  },
});
