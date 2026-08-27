# Load sink

Arrives in **Step 10**.

A Laravel Octane receiver with configurable `delay`, `fail_rate` and `status`, used as the
target for both the k6 load runs and the deterministic delivery-failure tests. It is a test
double that happens to run in a container: the delivery engine never depends on it.

Never part of the production profile.
