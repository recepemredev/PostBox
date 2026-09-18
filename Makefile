# PostBox task runner for Linux, macOS and CI. `task.ps1` is the same set of
# targets for Windows; both are thin dispatchers over the same docker compose
# invocations, so the behaviour lives in the compose files rather than here.
# CI asserts that the two files expose the same targets.

# TARGETS: up down fresh logs test stan format contract check ci prod-up prod-down bench help
.PHONY: up down fresh logs test stan format contract check ci prod-up prod-down bench help
.DEFAULT_GOAL := help

DEV   := docker compose --profile dev
PROD  := docker compose -f compose.yaml -f compose.prod.yaml

# The benchmark overlay is never layered on PROD. It carries the bench network
# that resolves D75, and the guarantee Step 10 makes is that the resolution
# cannot be active in the production profile — true here because the two
# invocations never share a file. CI asserts it independently
# (assert-bench-absent-from-production.sh).
BENCH := docker compose -f compose.yaml -f compose.bench.yaml

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

test:
	$(DEV) run --rm backend vendor/bin/pest --colors=always
	$(DEV) run --rm --no-deps frontend npm run test

stan:
	$(DEV) run --rm --no-deps backend vendor/bin/phpstan analyse --memory-limit=1G

# Pint rewrites files in the bind-mounted source tree, which the image's non-root
# user cannot write to. Running as the caller keeps the rewritten files owned by
# the caller rather than by root; task.ps1 uses root because Docker Desktop maps
# the write back to the host user anyway.
format:
	$(DEV) run --rm --no-deps --user $$(id -u):$$(id -g) backend vendor/bin/pint

# Regenerates contract/openapi.json from the backend, then frontend/src/types/api.d.ts
# from it — the one path both containers mount at a sibling of their own base path
# (compose.yaml, config/scramble.php). scramble:export reads real column types, so
# unlike `format` this needs the database up; not run with --no-deps.
contract:
	$(DEV) run --rm --user $$(id -u):$$(id -g) backend php artisan scramble:export
	$(DEV) run --rm --no-deps --user $$(id -u):$$(id -g) frontend npm run contract:types

check:
	$(DEV) run --rm --no-deps backend vendor/bin/pint --test
	$(DEV) run --rm --no-deps backend vendor/bin/phpstan analyse --memory-limit=1G
	$(DEV) run --rm --no-deps frontend npm run lint
	$(DEV) run --rm --no-deps frontend npm run typecheck
	$(DEV) run --rm --no-deps frontend npm run build

# Every gate the workflow runs, in one command and in the workflow's order.
#
# `check`, `test`, `prod-up` and `prod-down` are called rather than repeated, so
# the gate cannot drift from the targets it is made of. What this adds is the four
# assertions that until now existed only inside the workflow — target parity, the
# absent baseline, the production image contents and the scheduler singleton — plus
# the two lock files, which CI installs from and a working tree never re-reads, and
# the contract drift check (Step 12): `contract` regenerates in place, and a `git
# diff` catches an endpoint that changed without the committed contract following it.
#
# The scheduler assertion tears the compose project down. This is a pre-push gate,
# not something to run beside a live development stack.
ci:
	$(ensure_env)
	bash docker/scripts/assert-target-parity.sh
	@test ! -f backend/phpstan-baseline.neon \
		|| ( echo "FAIL: backend/phpstan-baseline.neon exists — D5 forbids a baseline" >&2; exit 1 )
	@echo "  ok  no phpstan baseline"
	$(DEV) run --rm --no-deps backend composer validate --strict
	$(DEV) run --rm --no-deps frontend npm ci --dry-run --no-audit --no-fund
	$(MAKE) check
	$(MAKE) contract
	git diff --exit-code contract/openapi.json frontend/src/types/api.d.ts
	$(MAKE) test
	$(PROD) build
	bash docker/scripts/assert-production-images.sh
	bash docker/scripts/assert-bench-absent-from-production.sh
	bash docker/scripts/assert-scheduler-singleton.sh
	bash docker/scripts/assert-stateless-workers.sh
	$(MAKE) prod-up
	$(MAKE) prod-down
	@echo "ci: every gate the workflow runs passed locally"

prod-up:
	$(ensure_env)
	$(PROD) up -d --build
	$(PROD) run --rm healthgate
	@echo "production profile is up and passed the deep health check"

prod-down:
	$(PROD) down --remove-orphans

# Phase 1 of benchmarking.md, which is the one that needs no judgement: bring
# the bench stack up, seed a tenant to publish into, and measure the sink
# ceiling every later figure is reported against. Phases 2 to 5 need the
# worker count changed and the ledger read between runs, so they are printed
# rather than run — a protocol that scrolled past unattended would produce
# numbers nobody watched.
bench:
	$(ensure_env)
	$(BENCH) up -d --build
	$(BENCH) run --rm healthgate
	$(BENCH) exec -T backend php artisan db:seed --class=BenchSeeder --force
	$(BENCH) --profile bench run --rm k6 run /load/sink-ceiling.js
	@echo ""
	@echo "Phase 1 done. Record sink_ceiling_rps before publishing anything else."
	@echo ""
	@echo "Phases 2-3 (APP_ID and API_KEY come from the seeder table above):"
	@echo "  $(BENCH) stop worker-deliveries worker-retries   # Phase 2: ingest alone"
	@echo "  $(BENCH) --profile bench run --rm -e APP_ID=... -e API_KEY=... k6 run /load/ingest.js"
	@echo "  $(BENCH) up -d worker-deliveries worker-retries  # Phase 3: end to end"
	@echo "  $(BENCH) --profile bench run --rm -e APP_ID=... -e API_KEY=... k6 run /load/ingest.js"
	@echo "  $(BENCH) exec -T postgres psql -U postbox_app -d postbox -v tenant_id=... -v minutes=10 -f - < load/queries/delivery-throughput.sql"
	@echo ""
	@echo "Phase 4 — backlog drain, repeat for N = 1, 3, 5 (fresh seed each time):"
	@echo "  $(BENCH) exec -T backend php artisan db:seed --class=BenchSeeder --force"
	@echo "  $(BENCH) stop worker-deliveries worker-retries"
	@echo "  $(BENCH) --profile bench run --rm -e APP_ID=... -e API_KEY=... -e STAGES=20:100s k6 run /load/ingest.js"
	@echo "  $(BENCH) exec -T redis redis-cli --scan --pattern '*queues:*'   # find the queue key, record its depth"
	@echo "  $(BENCH) up -d --scale worker-deliveries=N worker-deliveries; $(BENCH) up -d worker-retries"
	@echo "  $(BENCH) exec -T redis redis-cli llen <queue key>               # poll to zero, note the wall clock"
	@echo "  $(BENCH) exec -T postgres psql -U postbox_app -d postbox -v tenant_id=... -v minutes=15 -f - < load/queries/delivery-throughput.sql"
	@echo ""
	@echo "Phase 5 — degraded receiver, once (re-seeds a fresh tenant with two endpoints):"
	@echo "  $(BENCH) exec -T backend env BENCH_TARGET_URLS='http://sink:8000/sink?delay=0&fail_rate=0,http://sink:8000/sink?fail_rate=0.3&delay=500' php artisan db:seed --class=BenchSeeder --force"
	@echo "  $(BENCH) --profile bench run --rm -e APP_ID=... -e API_KEY=... k6 run /load/ingest.js"
	@echo "  $(BENCH) exec -T redis redis-cli llen <prefix>queues:retries    # sample a few times during the run"
	@echo "  $(BENCH) exec -T backend php artisan postbox:breakers"
	@echo "  $(BENCH) exec -T postgres psql -U postbox_app -d postbox -v tenant_id=... -v minutes=15 -f - < load/queries/delivery-throughput.sql"

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
	@echo "  make contract    regenerate contract/openapi.json and the frontend's generated types"
	@echo "  make check       Pint, Larastan, ESLint, tsc and next build"
	@echo "  make ci          every gate the workflow runs — the pre-push check"
	@echo "  make prod-up     build and start the production profile, then assert health"
	@echo "  make prod-down   stop the production profile"
	@echo "  make bench       bring up the bench stack, seed it and measure the sink ceiling"

# Lets `make logs backend` pass the service name through without make treating it
# as a target of its own.
%:
	@:
