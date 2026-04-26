from __future__ import annotations

from datetime import datetime, timedelta, timezone
import os
from pathlib import Path
from typing import Any

from fastapi import FastAPI, Header, HTTPException, Query, Response
from pydantic import BaseModel

try:
    from garminconnect import (
        Garmin,
        GarminConnectAuthenticationError,
        GarminConnectConnectionError,
        GarminConnectTooManyRequestsError,
    )
except ImportError:  # pragma: no cover - lets stub mode run without Garmin deps.
    Garmin = None
    GarminConnectAuthenticationError = Exception
    GarminConnectConnectionError = Exception
    GarminConnectTooManyRequestsError = Exception

app = FastAPI(title="garmin-connector")


class ConnectorError(RuntimeError):
    def __init__(self, code: str, status_code: int = 502, detail: str | None = None):
        super().__init__(detail or code)
        self.code = code
        self.status_code = status_code
        self.detail = detail or code


def _check_api_key(header_key: str | None) -> None:
    expected = os.getenv("CONNECTOR_API_KEY", "").strip()
    if expected and (header_key or "").strip() != expected:
        raise HTTPException(status_code=401, detail="Unauthorized connector key")


class ConnectStartRequest(BaseModel):
    userRef: str
    username: str | None = None
    email: str | None = None
    password: str | None = None
    mfaCode: str | None = None


class SyncRequest(BaseModel):
    userRef: str
    fromIso: str | None = None
    toIso: str | None = None
    email: str | None = None
    password: str | None = None
    mfaCode: str | None = None
    activityType: str | None = None
    limit: int | None = None


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _connector_mode() -> str:
    mode = _env("GARMIN_CONNECTOR_MODE", "auto").lower()
    if mode not in {"auto", "stub", "live"}:
        mode = "auto"
    if mode == "auto":
        return "live" if _env("GARMIN_EMAIL") and _env("GARMIN_PASSWORD") else "stub"
    return mode


def _max_limit() -> int:
    try:
        return max(1, min(1000, int(_env("GARMIN_SYNC_LIMIT_MAX", "100"))))
    except ValueError:
        return 100


def _requested_limit(limit: int | None) -> int:
    default_limit = 30
    if limit is None:
        return min(default_limit, _max_limit())
    return max(1, min(limit, _max_limit()))


def _safe_user_ref(user_ref: str) -> str:
    safe = "".join(ch if ch.isalnum() or ch in {"-", "_"} else "_" for ch in user_ref)
    return safe or "default"


def _token_dir(user_ref: str) -> str:
    base = Path(_env("GARMIN_TOKEN_DIR", ".garmin_tokens"))
    return str((base / _safe_user_ref(user_ref)).resolve())


def _parse_iso(value: str | None) -> datetime | None:
    if not value:
        return None
    text = value.strip()
    if not text:
        return None
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _date_str(value: str | None, fallback: datetime) -> str:
    parsed = _parse_iso(value)
    return (parsed or fallback).date().isoformat()


def _stub_items(from_iso: str | None, to_iso: str | None) -> list[dict[str, Any]]:
    now = datetime.now(timezone.utc)
    start = now - timedelta(days=2)
    if from_iso:
        start = _parse_iso(from_iso) or start
    _ = to_iso
    return [
        {
            "sourceActivityId": f"garmin-{int(start.timestamp())}",
            "startTimeIso": start.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
            "durationSec": 2400,
            "distanceM": 6500,
            "activityType": "running",
            "name": "Stub Garmin activity",
        }
    ]


def _raise_http_error(exc: ConnectorError) -> None:
    raise HTTPException(
        status_code=exc.status_code,
        detail={"error": exc.code, "message": exc.detail},
    )


def _mfa_callback(mfa_code: str | None):
    def _prompt() -> str:
        code = (mfa_code or _env("GARMIN_MFA_CODE")).strip()
        if not code:
            raise ConnectorError(
                "GARMIN_MFA_REQUIRED",
                409,
                "Set GARMIN_MFA_CODE or pass mfaCode for the first Garmin login.",
            )
        return code

    return _prompt


def _garmin_client(
    user_ref: str,
    email: str | None = None,
    password: str | None = None,
    mfa_code: str | None = None,
):
    if Garmin is None:
        raise ConnectorError("GARMIN_LIBRARY_NOT_INSTALLED", 500)

    garmin_email = (email or _env("GARMIN_EMAIL")).strip()
    garmin_password = (password or _env("GARMIN_PASSWORD")).strip()
    tokenstore = _token_dir(user_ref)
    Path(tokenstore).mkdir(parents=True, exist_ok=True)

    client = Garmin(
        garmin_email or None,
        garmin_password or None,
        prompt_mfa=_mfa_callback(mfa_code),
    )
    try:
        client.login(tokenstore)
        return client
    except ConnectorError:
        raise
    except GarminConnectTooManyRequestsError as exc:
        raise ConnectorError("GARMIN_RATE_LIMITED", 429, str(exc)) from exc
    except GarminConnectAuthenticationError as exc:
        raise ConnectorError("GARMIN_AUTH_FAILED", 401, str(exc)) from exc
    except GarminConnectConnectionError as exc:
        raise ConnectorError("GARMIN_CONNECT_FAILED", 502, str(exc)) from exc
    except Exception as exc:
        raise ConnectorError("GARMIN_CONNECTOR_FAILED", 502, str(exc)) from exc


def _first(activity: dict[str, Any], *keys: str) -> Any:
    for key in keys:
        value = activity.get(key)
        if value not in (None, ""):
            return value
    return None


def _activity_type(activity: dict[str, Any]) -> str | None:
    activity_type = activity.get("activityType")
    if isinstance(activity_type, dict):
        return (
            activity_type.get("typeKey")
            or activity_type.get("typeName")
            or activity_type.get("activityTypeKey")
        )
    if isinstance(activity_type, str):
        return activity_type
    return None


def _activity_start_iso(activity: dict[str, Any]) -> str:
    raw = _first(activity, "startTimeGMT", "startTimeGmt", "startTimeLocal", "startTime")
    if isinstance(raw, str):
        parsed = _parse_iso(raw)
        if parsed:
            return parsed.replace(microsecond=0).isoformat().replace("+00:00", "Z")
        try:
            parsed_local = datetime.fromisoformat(raw)
            return (
                parsed_local.replace(tzinfo=timezone.utc)
                .replace(microsecond=0)
                .isoformat()
                .replace("+00:00", "Z")
            )
        except ValueError:
            pass
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _as_int(value: Any, default: int = 0) -> int:
    try:
        return int(round(float(value)))
    except (TypeError, ValueError):
        return default


def _normalize_activity(activity: dict[str, Any]) -> dict[str, Any]:
    activity_id = _first(activity, "activityId", "activityUUID", "id")
    duration = _first(activity, "duration", "elapsedDuration", "movingDuration")
    distance = _first(activity, "distance", "distanceInMeters")
    item = {
        "sourceActivityId": str(activity_id or ""),
        "startTimeIso": _activity_start_iso(activity),
        "durationSec": _as_int(duration),
        "distanceM": _as_int(distance),
        "activityType": _activity_type(activity),
        "name": _first(activity, "activityName", "name"),
        "averageHr": _as_int(_first(activity, "averageHR", "averageHr"), 0),
        "maxHr": _as_int(_first(activity, "maxHR", "maxHr"), 0),
        "calories": _as_int(activity.get("calories"), 0),
        "elevationGainM": _as_int(_first(activity, "elevationGain", "elevationGainMeters"), 0),
    }
    return {key: value for key, value in item.items() if value not in (None, "")}


def _fetch_activities(client: Any, payload: SyncRequest) -> list[dict[str, Any]]:
    limit = _requested_limit(payload.limit)
    if payload.fromIso or payload.toIso:
        now = datetime.now(timezone.utc)
        start = _date_str(payload.fromIso, now - timedelta(days=30))
        end = _date_str(payload.toIso, now)
        raw = client.get_activities_by_date(
            start,
            end,
            activitytype=payload.activityType,
            sortorder="asc",
        )
        return [_normalize_activity(row) for row in raw[:limit] if isinstance(row, dict)]

    raw = client.get_activities(0, limit, activitytype=payload.activityType)
    if not isinstance(raw, list):
        return []
    return [_normalize_activity(row) for row in raw if isinstance(row, dict)]


@app.post("/v1/garmin/connect/start")
def connect_start(payload: ConnectStartRequest, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    mode = _connector_mode()
    if mode == "stub":
        return {"accountRef": f"garmin-{payload.userRef}", "status": "connected", "connectorMode": "stub"}
    try:
        client = _garmin_client(payload.userRef, payload.email, payload.password, payload.mfaCode)
    except ConnectorError as exc:
        _raise_http_error(exc)
    return {
        "accountRef": f"garmin-{payload.userRef}",
        "status": "connected",
        "connectorMode": "live",
        "displayName": getattr(client, "display_name", None),
        "fullName": getattr(client, "full_name", None),
    }


@app.post("/v1/garmin/sync")
def garmin_sync(payload: SyncRequest, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    mode = _connector_mode()
    if mode == "stub":
        return {
            "syncRunId": f"sync-{payload.userRef}",
            "connectorMode": "stub",
            "items": _stub_items(payload.fromIso, payload.toIso),
        }
    try:
        client = _garmin_client(payload.userRef, payload.email, payload.password, payload.mfaCode)
        items = _fetch_activities(client, payload)
    except ConnectorError as exc:
        _raise_http_error(exc)
    except Exception as exc:
        _raise_http_error(ConnectorError("GARMIN_SYNC_FAILED", 502, str(exc)))
    return {
        "syncRunId": f"sync-{payload.userRef}",
        "connectorMode": "live",
        "items": items,
    }


@app.get("/v1/garmin/accounts/{user_ref}/status")
def garmin_status(user_ref: str, x_connector_key: str | None = Header(default=None)):
    _check_api_key(x_connector_key)
    mode = _connector_mode()
    if mode == "stub":
        return {"userRef": user_ref, "connected": True, "health": "ok", "connectorMode": "stub"}
    try:
        client = _garmin_client(user_ref)
    except ConnectorError as exc:
        _raise_http_error(exc)
    return {
        "userRef": user_ref,
        "connected": True,
        "health": "ok",
        "connectorMode": "live",
        "displayName": getattr(client, "display_name", None),
        "fullName": getattr(client, "full_name", None),
    }


@app.get("/v1/garmin/activities/{activity_id}/download")
def download_activity(
    activity_id: str,
    userRef: str = Query(default="default"),
    format: str = Query(default="tcx", pattern="^(fit|tcx|gpx|kml|csv)$"),
    x_connector_key: str | None = Header(default=None),
):
    _check_api_key(x_connector_key)
    if _connector_mode() == "stub":
        raise HTTPException(status_code=409, detail={"error": "GARMIN_LIVE_MODE_REQUIRED"})
    try:
        client = _garmin_client(userRef)
        fmt_map = {
            "fit": Garmin.ActivityDownloadFormat.ORIGINAL,
            "tcx": Garmin.ActivityDownloadFormat.TCX,
            "gpx": Garmin.ActivityDownloadFormat.GPX,
            "kml": Garmin.ActivityDownloadFormat.KML,
            "csv": Garmin.ActivityDownloadFormat.CSV,
        }
        data = client.download_activity(activity_id, fmt_map[format])
    except ConnectorError as exc:
        _raise_http_error(exc)
    except Exception as exc:
        _raise_http_error(ConnectorError("GARMIN_DOWNLOAD_FAILED", 502, str(exc)))

    media_types = {
        "fit": "application/zip",
        "tcx": "application/vnd.garmin.tcx+xml",
        "gpx": "application/gpx+xml",
        "kml": "application/vnd.google-earth.kml+xml",
        "csv": "text/csv",
    }
    return Response(content=data, media_type=media_types[format])
