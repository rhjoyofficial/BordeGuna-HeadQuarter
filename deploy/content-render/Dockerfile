# The Bangla card render service (docs/85 A.6, C.8; docs/88 A.5). DEFINED, NOT STARTED.
#
# Why a browser: on 12 July 2026 GraphicsMagick drew "গার্ডেন" as "গার্ডনে" and "তেল" as "তলে", because it
# has no shaping engine. Headless Chromium shapes Bangla correctly; this image is only that.
#
# ⛔ Not referenced by deploy/docker-compose.yml, so deploy.sh never builds or starts it. It is started by
# a person at gate P6-G2 with deploy/content-render/docker-compose.content-render.yml, after its
# self-test (selftest.mjs) has been looked at by eye.
#
# ⚠ The base tag below is the Playwright image line current when this was written; confirm the tag and
# pin its digest at P6-G2 (not verified on 22 September 2026).
FROM mcr.microsoft.com/playwright:v1.55.0-noble

# Fonts with the Bengali script: fonts-noto-core carries Noto Sans Bengali and Noto Serif Bengali.
RUN apt-get update \
 && apt-get install -y --no-install-recommends fonts-noto-core \
 && rm -rf /var/lib/apt/lists/* \
 && fc-cache -f

WORKDIR /srv
COPY package.json server.mjs selftest.mjs ./
RUN npm install --omit=dev --no-audit --no-fund

USER pwuser
EXPOSE 8080
CMD ["node", "server.mjs"]
