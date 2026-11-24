# Test runner: Python with Playwright (Chromium headless shell), imaplib and
# requests. Base image and the Debian snapshot are immutable, and every
# Python package is installed from the hash-locked requirements.
FROM python:3.12-slim-bookworm@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

COPY requirements.txt /tmp/requirements.txt
COPY debian.sources /etc/apt/sources.list.d/debian.sources
ADD --checksum=sha256:c3e50596aef52c58f8fda3c6b3411c33d34c88b177b3ed1c2243359fd3b8ba20 \
    https://cdn.playwright.dev/builds/cft/156.0.8078.4/linux64/chrome-headless-shell-linux64.zip /tmp/chromium.zip
ADD --checksum=sha256:ee986434d158f74fb5d9c5ff1092026ceedb63f324fe90b5be89460926b7b679 \
    https://cdn.playwright.dev/dbazure/download/playwright/builds/ffmpeg/1013/ffmpeg-linux.zip /tmp/ffmpeg.zip
RUN pip install --no-cache-dir --require-hashes -r /tmp/requirements.txt \
 && python -m playwright install-deps chromium \
 && python -c "import zipfile; zipfile.ZipFile('/tmp/chromium.zip').extractall('/ms-playwright/chromium_headless_shell-1248')" \
 && python -c "import zipfile; zipfile.ZipFile('/tmp/ffmpeg.zip').extractall('/ms-playwright/ffmpeg-1013')" \
 && chmod 0755 /ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/chrome-headless-shell \
        /ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/libEGL.so \
        /ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/libGLESv2.so \
        /ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/libvk_swiftshader.so \
        /ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/libvulkan.so.1 \
        /ms-playwright/ffmpeg-1013/ffmpeg-linux \
 && touch /ms-playwright/chromium_headless_shell-1248/DEPENDENCIES_VALIDATED \
        /ms-playwright/chromium_headless_shell-1248/INSTALLATION_COMPLETE \
        /ms-playwright/ffmpeg-1013/DEPENDENCIES_VALIDATED \
        /ms-playwright/ffmpeg-1013/INSTALLATION_COMPLETE \
 && apt-get install -y --no-install-recommends openssl \
 && rm -rf /var/lib/apt/lists/* /tmp/chromium.zip /tmp/ffmpeg.zip /tmp/requirements.txt \
 && python -c "import importlib.metadata as m; print('playwright', m.version('playwright'), 'pytest', m.version('pytest'), 'requests', m.version('requests'))"
