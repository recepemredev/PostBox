# syntax=docker/dockerfile:1

ARG NODE_VERSION=22

# --- deps -------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS deps

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

# --- dev --------------------------------------------------------------------
# The source tree is bind-mounted and node_modules lives in a named volume that
# this stage's install populates on first start.
FROM node:${NODE_VERSION}-alpine AS dev

WORKDIR /app

ENV NEXT_TELEMETRY_DISABLED=1

COPY docker/frontend/entrypoint.sh /usr/local/bin/postbox-entrypoint
RUN chmod +x /usr/local/bin/postbox-entrypoint

# A named volume inherits the ownership of the directory it shadows. Creating
# node_modules as `node` here is what lets the non-root process install into it.
RUN mkdir -p /app/node_modules && chown -R node:node /app

USER node

ENTRYPOINT ["postbox-entrypoint"]
CMD ["npm", "run", "dev"]

# --- builder ----------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS builder

WORKDIR /app

ENV NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /app/node_modules ./node_modules
COPY frontend/ ./

RUN npm run build

# --- prod -------------------------------------------------------------------
# Only the standalone server output is carried over: no source tree, no dev
# dependency, no package manager.
FROM node:${NODE_VERSION}-alpine AS prod

WORKDIR /app

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

COPY --from=builder --chown=node:node /app/.next/standalone ./
COPY --from=builder --chown=node:node /app/.next/static ./.next/static
COPY --from=builder --chown=node:node /app/public ./public

USER node

EXPOSE 3000

CMD ["node", "server.js"]
