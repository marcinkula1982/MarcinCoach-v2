# Garmin Connector (python-garminconnect)

This service is a thin adapter around [`python-garminconnect`](https://github.com/cyberjunky/python-garminconnect) used by the PHP backend.

Important: this is an unofficial Garmin Connect connector. It logs in as a user and uses Garmin Connect endpoints, not the official Garmin Activity API.

## Endpoints
- `POST /v1/garmin/connect/start`
- `POST /v1/garmin/sync`
- `GET /v1/garmin/accounts/{userRef}/status`
- `GET /v1/garmin/activities/{activityId}/download?userRef={userRef}&format=tcx`

`/sync` returns normalized items compatible with Laravel:

```json
{
  "items": [
    {
      "sourceActivityId": "123",
      "startTimeIso": "2026-04-20T11:00:00Z",
      "durationSec": 2400,
      "distanceM": 6500,
      "activityType": "running"
    }
  ]
}
```

## Local run
```bash
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app:app --host 0.0.0.0 --port 8090
```

## Auth
Set `CONNECTOR_API_KEY` and pass it in `x-connector-key` header from PHP.

## Modes

- `GARMIN_CONNECTOR_MODE=auto` (default) - live when `GARMIN_EMAIL` and `GARMIN_PASSWORD` are present, otherwise stub.
- `GARMIN_CONNECTOR_MODE=stub` - never touches Garmin, returns deterministic sample activity.
- `GARMIN_CONNECTOR_MODE=live` - requires real Garmin login/token data.

Live env:

```bash
CONNECTOR_API_KEY=change-me
GARMIN_CONNECTOR_MODE=live
GARMIN_EMAIL=runner@example.com
GARMIN_PASSWORD=...
GARMIN_TOKEN_DIR=.garmin_tokens
GARMIN_SYNC_LIMIT_MAX=100
```

For the first login with MFA, temporarily set:

```bash
GARMIN_MFA_CODE=123456
```

After a successful `/connect/start`, remove `GARMIN_MFA_CODE`. Tokens are stored per `userRef` under `GARMIN_TOKEN_DIR`.

## Smoke

```bash
curl -X POST http://127.0.0.1:8090/v1/garmin/connect/start ^
  -H "content-type: application/json" ^
  -H "x-connector-key: change-me" ^
  -d "{\"userRef\":\"1\"}"

curl -X POST http://127.0.0.1:8090/v1/garmin/sync ^
  -H "content-type: application/json" ^
  -H "x-connector-key: change-me" ^
  -d "{\"userRef\":\"1\",\"fromIso\":\"2026-04-01T00:00:00Z\",\"toIso\":\"2026-04-26T23:59:59Z\",\"limit\":30}"
```

## Production smoke

Verified on IQHost on 2026-04-26 in read-only live mode:

- `POST /v1/garmin/connect/start` -> HTTP 200, `connectorMode=live`.
- `POST /v1/garmin/sync` for the last 30 days -> HTTP 200, 9 normalized activities.
- `GET /v1/garmin/accounts/1/status` -> HTTP 200, `connected=true`, `connectorMode=live`.
- `GET /v1/garmin/activities/{activityId}/download?userRef=1&format=tcx` -> HTTP 200, TCX XML downloaded.

`GARMIN_MFA_CODE` was not required during this smoke and is not kept in the connector env after login. Credentials and tokens stay outside git.
