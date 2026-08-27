# PostBox task runner for Linux, macOS and CI. `task.ps1` is the same set of
# targets for Windows; both are thin dispatchers over the same docker compose
# invocations, so the behaviour lives in the compose files rather than here.
# CI asserts that the two files expose the same targets.

# TARGETS: up down fresh logs test stan format check prod-up prod-down bench help
.PHONY: up down fresh logs test stan format check prod-up prod-down bench help
.DEFAULT_GOAL := help

DEV  := docker compose --profile dev
PROD := docker compose -f compose.yaml -f compose.prod.yaml

# Creates .env on first run and fills in the application key. A Laravel key is
# `base64:` followed by 32 random bytes; generating it here rather than inside a
# container keeps the first run from needing an image that is not built yet.
define ensure_env
	@test -f .env || (cp .env.example .env && echo "created .env from .env.example")
	@test -f backend/.env || printf '%s\n' \
		'# Intentionally empty. Configuration comes from the container environment,' \
		'# which Compose fills from the single .env at the repository root. Dotenv' \
		'# reads this path unconditionally and the test runner reports the failed' \
		'# read as a warning; that is the only reason the file exists. Do not put' \
		'# values here — they would be a second source of truth.' > backend/.env
	@grep -q '^APP_KEY=.\+' .env || ( \
		echo "generating APP_KEY"; \
		key="base64:$$(openssl rand -base64 32)"; \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$key|" .env && rm -f .env.bak \
	)
endef

up:
	$(ensure_env)
	$(DEV) up -d --build
	$(DEV) run --rm healthgate
	@echo "PostBox is up on http://localhost:$$(grep '^HTTP_PORT=' .env | cut -d= -f2)"

down:
	$(DEV) down --remove-orphans

fresh:
	$(DEV) down --remove-orphans --volumes
	$(DEV) build --no-cache
	$(MAKE) up

logs:
	$(DEV) logs --follow $(filter-out $@,$(MAKECMDGOALS))

# The frontend has no test suite until the dashboard exists (Step 13).
test:
	$(DEV) run --rm backend vendor/bin/pest --colors=always

stan:
	$(DEV) run --rm --no-deps backend vendor/bin/phpstan analyse --memory-limit=1G

# Pint rewrites files in the bind-mounted source tree, which the image's non-root
# user cannot write to. Running as the caller keeps the rewritten files owned by
# the caller rather than by root; task.ps1 uses root because Docker Desktop maps
# the write back to the host user anyway.
format:
	$(DEV) run --rm --no-deps --user $$(id -u):$$(id -g) backend vendor/bin/pint

check:
	$(DEV) run --rm --no-deps backend vendor/bin/pint --test
	$(DEV) run --rm --no-deps backend vendor/bin/phpstan analyse --memory-limit=1G
	$(DEV) run --rm --no-deps frontend npm run lint
	$(DEV) run --rm --no-deps frontend npm run typecheck
	$(DEV) run --rm --no-deps frontend npm run build

prod-up:
	$(ensure_env)
	$(PROD) up -d --build
	$(PROD) run --rm healthgate
	@echo "production profile is up and passed the deep health check"

prod-down:
	$(PROD) down --remove-orphans

bench:
	@echo "bench: the load harness and the k6 scripts arrive in Step 10." >&2
	@exit 1

help:
	@echo "PostBox task runner"
	@echo ""
	@echo "  make up          start the development stack and wait for it to be healthy"
	@echo "  make down        stop the stack, keeping the volumes"
	@echo "  make fresh       delete the volumes and rebuild from empty"
	@echo "  make logs [svc]  follow the logs"
	@echo "  make test        run the backend test suite"
	@echo "  make stan        run Larastan at max"
	@echo "  make format      rewrite the backend to the Pint style"
	@echo "  make check       Pint, Larastan, ESLint, tsc and next build"
	@echo "  make prod-up     build and start the production profile, then assert health"
	@echo "  make prod-down   stop the production profile"
	@echo "  make bench       run the benchmark protocol (Step 10)"

# Lets `make logs backend` pass the service name through without make treating it
# as a target of its own.
%:
	@:
