# Garmin Connector (python-garminconnect)

This service is a thin adapter around [`python-garminconnect`](https://github.com/cyberjunky/python-garminconnect) used by the PHP backend.

## Endpoints
- `POST /v1/garmin/connect/start`
- `POST /v1/garmin/sync`
- `GET /v1/garmin/accounts/{userRef}/status`

## Local run
```bash
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app:app --host 0.0.0.0 --port 8090
```

## Auth
Set `CONNECTOR_API_KEY` and pass it in `x-connector-key` header from PHP.
