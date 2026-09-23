# syntax=docker/dockerfile:1

# Image for sdk/ts's own tooling (ESLint, tsc, Vitest, the dist build). Never
# part of compose.prod.yaml — the SDK is a package consumers install into their
# own project, not something this application serves.

ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-alpine AS dev

# Mirrors the host's own repo/sdk/ts nesting exactly, rather than the flat
# /app every other image uses: contract:types and the test suite locate the
# contract and the shared conformance vectors with a relative ../../contract
# and ../../../contract respectively, paths relative to their own file, so
# the container's contract mount has to sit exactly as many levels above the
# source tree as it does on the host (compose.yaml mounts ./contract at
# /workspace/contract, a sibling of /workspace/sdk).
WORKDIR /workspace/sdk/ts

COPY docker/sdk-ts/entrypoint.sh /usr/local/bin/postbox-entrypoint
RUN chmod +x /usr/local/bin/postbox-entrypoint

# A named volume inherits the ownership of the directory it shadows. Creating
# node_modules as `node` here is what lets the non-root process install into it.
RUN mkdir -p node_modules && chown -R node:node /workspace/sdk/ts

USER node

ENTRYPOINT ["postbox-entrypoint"]
CMD ["npm", "--version"]
