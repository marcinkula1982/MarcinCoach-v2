> HISTORYCZNE - nie uzywac jako aktualnej instrukcji.
> Aktywne dokumenty: `docs/status.md` (wykonane funkcjonalnosci), `docs/roadmap.md` (plan), `docs/deploy/frontend-iqhost-deploy.txt` (deploy frontu), `docs/integrations.md` (integracje).
# Frontend Deploy Runbook (coach subdomain)

This runbook defines the permanent deploy standard for frontend on `coach.host89998.iqhs.pl`.

## Server Paths

- Bare repo: `~/domains/coach.host89998.iqhs.pl/coach.git`
- Work tree: `/home/host89998/domains/coach.host89998.iqhs.pl/app-laravel`
- Public web root: `/home/host89998/domains/coach.host89998.iqhs.pl/public_html`
- Deploy log: `/home/host89998/.iq-git/deploy.log`

## Standard Flow (source of truth)

1. Push code to `main`.
2. `post-receive` checks out code to work tree.
3. Hook runs `npm ci` and `npm run build` on server.
4. Hook copies `dist/*` to `public_html`.

`dist/` must stay untracked in git. Artifact is built on server.

## post-receive (final version)

Replace hook content with:

```bash
#!/bin/bash
set -euo pipefail

DEPLOY_BRANCH='main'
DEPLOY_DIR='/home/host89998/domains/coach.host89998.iqhs.pl/app-laravel'
PUBLIC_DIR='/home/host89998/domains/coach.host89998.iqhs.pl/public_html'
LOG_FILE='/home/host89998/.iq-git/deploy.log'

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

while read oldrev newrev refname; do
  branch="$(git rev-parse --symbolic --abbrev-ref "$refname" 2>/dev/null || true)"
  if [ "$branch" = "$DEPLOY_BRANCH" ]; then
    mkdir -p "$DEPLOY_DIR"
    git --work-tree="$DEPLOY_DIR" --git-dir="$(pwd)" checkout -f "$DEPLOY_BRANCH"

    cd "$DEPLOY_DIR"
    npm ci
    npm run build

    mkdir -p "$PUBLIC_DIR"
    cp -r dist/* "$PUBLIC_DIR"/

    echo "$(date '+%Y-%m-%d %H:%M:%S') | push | repo=coach branch=$branch status=ok build=ok" >> "$LOG_FILE"
    echo "Deploy zakonczony: $DEPLOY_BRANCH -> $DEPLOY_DIR -> $PUBLIC_DIR"
  fi
done
```

## Update Hook Safely

Do not append using `>>`. Always overwrite whole file:

```powershell
ssh host89998@h3.iqhs.eu "cat > ~/domains/coach.host89998.iqhs.pl/coach.git/hooks/post-receive << 'EOF'
#!/bin/bash
set -euo pipefail
DEPLOY_BRANCH='main'
DEPLOY_DIR='/home/host89998/domains/coach.host89998.iqhs.pl/app-laravel'
PUBLIC_DIR='/home/host89998/domains/coach.host89998.iqhs.pl/public_html'
LOG_FILE='/home/host89998/.iq-git/deploy.log'
export PATH=\"/usr/local/bin:/usr/bin:/bin:$PATH\"
while read oldrev newrev refname; do
  branch=\"$(git rev-parse --symbolic --abbrev-ref \"$refname\" 2>/dev/null || true)\"
  if [ \"$branch\" = \"$DEPLOY_BRANCH\" ]; then
    mkdir -p \"$DEPLOY_DIR\"
    git --work-tree=\"$DEPLOY_DIR\" --git-dir=\"$(pwd)\" checkout -f \"$DEPLOY_BRANCH\"
    cd \"$DEPLOY_DIR\"
    npm ci
    npm run build
    mkdir -p \"$PUBLIC_DIR\"
    cp -r dist/* \"$PUBLIC_DIR\"/
    echo \"$(date '+%Y-%m-%d %H:%M:%S') | push | repo=coach branch=$branch status=ok build=ok\" >> \"$LOG_FILE\"
  fi
done
EOF
chmod +x ~/domains/coach.host89998.iqhs.pl/coach.git/hooks/post-receive"
```

## Diagnostics

### Check hook content

```powershell
ssh host89998@h3.iqhs.eu "cat ~/domains/coach.host89998.iqhs.pl/coach.git/hooks/post-receive"
```

### Check runtime versions

```powershell
ssh host89998@h3.iqhs.eu "node -v && npm -v"
```

### Tail deploy log

```powershell
ssh host89998@h3.iqhs.eu "tail -n 100 ~/.iq-git/deploy.log"
```

### Check published files

```powershell
ssh host89998@h3.iqhs.eu "ls -la /home/host89998/domains/coach.host89998.iqhs.pl/public_html"
```

## Rollback

1. On local machine checkout last known good commit.
2. Push that commit to `main`.
3. Confirm hook ran and log reports success.

## Emergency Fallback (manual publish)

If server-side build is temporarily unavailable:

```powershell
npm run build
scp -r dist/* host89998@h3.iqhs.eu:domains/coach.host89998.iqhs.pl/public_html/
```

This is recovery only, not the primary deploy path.
