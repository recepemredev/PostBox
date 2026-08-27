/**
 * Deliberately .mjs rather than .ts: Next traces the config file into the
 * standalone output, and a TypeScript config drags the TypeScript compiler into
 * the production image with it. The JSDoc annotation keeps the editor typing.
 *
 * @type {import('next').NextConfig}
 */
const nextConfig = {
  // The production image copies only the standalone server output, so it carries
  // no dev dependency and no source tree.
  output: "standalone",

  // Next's tracer follows a lazy require inside the framework and pulls the
  // 8 MB TypeScript compiler into that output. Nothing compiles types at
  // runtime — the server is already built — so it is excluded. CI asserts that
  // it stays out.
  outputFileTracingExcludes: {
    "*": ["node_modules/typescript/**"],
  },
};

export default nextConfig;
