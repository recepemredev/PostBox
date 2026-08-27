# syntax=docker/dockerfile:1

# The unprivileged image runs the master process as a non-root user and listens on
# 8080 rather than 80. The configuration is baked in rather than mounted, so the
# development and production stacks cannot drift apart in cookie, header or
# buffering behaviour — which is the entire reason nginx is present in both.
FROM nginxinc/nginx-unprivileged:1.27-alpine

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/conf.d/postbox.conf /etc/nginx/conf.d/default.conf

EXPOSE 8080
