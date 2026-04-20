from datetime import datetime, timedelta, timezone
import os
from typing import Any, Dict, List

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

app = FastAPI(title="garmin-connector")


def _check_api_key(header_key: str | None) -> None:
    expected = os.getenv("CONNECTOR_API_KEY", "").strip()
    if expected and (header_key or "").strip() != expected:
        raise HTTPException(status_code=401, detail="Unauthorized connector key")


class ConnectStartRequest(BaseModel):
    userRef: str
    username: str | None = None


class SyncRequest(BaseModel):
    userRef: str
    fromIso: str | None = None
    toIso: str | None = None


def _stub_items(from_iso: str | None, to_iso: str | None) -> List[Dict[str, Any]]:
    now = datetime.now(timezone.utc)
    start = now - timedelta(days=2)
    if from_iso:
        try:
            start = datetime.fromisoformat(from_iso.replace("Z", "+00:00"))
        except ValueError:
            pass
    _ = to_iso
    return [
        {
            "sourceActivityId": f"garmin-{int(start.timestamp())}",
            "startTimeIso": start.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
            "durationSec": 2400,
            "distanceM": 6500,
        }
    ]


@app.post("/v1/garmin/connect/start")
def connect_start(payload: ConnectStartRequest, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    return {"accountRef": f"garmin-{payload.userRef}", "status": "connected"}


@app.post("/v1/garmin/sync")
def garmin_sync(payload: SyncRequest, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    # For MVP rollout this endpoint returns normalized payload.
    # Production connector can replace this logic with python-garminconnect API calls.
    return {"syncRunId": f"sync-{payload.userRef}", "items": _stub_items(payload.fromIso, payload.toIso)}


@app.get("/v1/garmin/accounts/{user_ref}/status")
def garmin_status(user_ref: str, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    return {"userRef": user_ref, "connected": True, "health": "ok"}
