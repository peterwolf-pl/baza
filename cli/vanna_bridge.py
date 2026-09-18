#!/usr/bin/env python3
from __future__ import annotations

import contextlib
import io
import json
import re
import shutil
import sys
from pathlib import Path


def respond(payload: dict[str, object], exit_code: int = 0) -> None:
    target = getattr(sys, "__stdout__", sys.stdout) or sys.stdout
    target.write(json.dumps(payload, ensure_ascii=False))
    target.flush()
    raise SystemExit(exit_code)


def fail(message: str, details: str | None = None, exit_code: int = 1) -> None:
    payload: dict[str, object] = {"ok": False, "error": message}
    if details:
        payload["details"] = details
    respond(payload, exit_code=exit_code)


def safe_slug(value: str) -> str:
    slug = re.sub(r"[^a-zA-Z0-9_.-]+", "-", value).strip("-._")
    return slug or "default"


def load_payload() -> dict[str, object]:
    if len(sys.argv) > 1:
        payload_path = Path(sys.argv[1])
        try:
            raw = payload_path.read_text(encoding="utf-8")
        except Exception as exc:
            fail("Nie mozna odczytac pliku payload dla mostka Vanna.", str(exc))
    else:
        raw = sys.stdin.read()

    if not raw.strip():
        fail("Brak danych wejsciowych dla mostka Vanna.")

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail("Nieprawidlowy JSON dla mostka Vanna.", str(exc))

    if not isinstance(payload, dict):
        fail("Payload Vanna musi byc obiektem JSON.")

    return payload


def create_client(payload: dict[str, object]):
    provider = str(payload.get("provider") or "").strip().lower()
    api_key = str(payload.get("api_key") or "").strip()
    model = str(payload.get("model") or "").strip()
    cache_namespace = safe_slug(str(payload.get("cache_namespace") or "default"))
    schema_hash = str(payload.get("schema_hash") or "").strip()
    local_cache_root = Path(str(payload.get("local_cache_root") or Path.cwd() / "tmp" / "vanna_local"))

    if provider == "hosted":
        if not api_key or not model:
            fail("Dla trybu hosted ustaw VANNA_API_KEY oraz VANNA_MODEL.")

        try:
            from vanna.legacy.remote import VannaDefault  # type: ignore
        except Exception:
            try:
                from vanna.remote import VannaDefault  # type: ignore
            except Exception as exc:
                fail("Nie mozna zaladowac VannaDefault. Zainstaluj pakiet vanna.", str(exc))

        try:
            return VannaDefault(model=model, api_key=api_key), None
        except Exception as exc:
            fail("Nie udalo sie uruchomic Vanna Hosted.", str(exc))

    if provider == "openai":
        if not api_key:
            fail("Dla trybu openai ustaw OPENAI_API_KEY.")
        if not model:
            model = "gpt-4.1-mini"

        try:
            from vanna.legacy.chromadb import ChromaDB_VectorStore  # type: ignore
            from vanna.legacy.openai import OpenAI_Chat  # type: ignore
        except Exception as exc:
            fail(
                "Brakuje zaleznosci dla lokalnej integracji Vanna. Zainstaluj: pip install vanna openai chromadb.",
                str(exc),
            )

        class LocalVanna(ChromaDB_VectorStore, OpenAI_Chat):
            def __init__(self, config=None):
                ChromaDB_VectorStore.__init__(self, config=config)
                OpenAI_Chat.__init__(self, config=config)

        cache_dir = local_cache_root / cache_namespace
        marker_path = Path(str(payload.get("bridge_cache_root") or Path.cwd() / "tmp" / "vanna_cache"))
        marker_path = marker_path / f"{cache_namespace}.json"

        if schema_hash and marker_path.exists():
            try:
                marker_data = json.loads(marker_path.read_text(encoding="utf-8"))
            except Exception:
                marker_data = {}
            if marker_data.get("schema_hash") != schema_hash and cache_dir.exists():
                shutil.rmtree(cache_dir, ignore_errors=True)

        cache_dir.mkdir(parents=True, exist_ok=True)

        try:
            client = LocalVanna(
                config={
                    "api_key": api_key,
                    "model": model,
                    "path": str(cache_dir),
                    "n_results": 8,
                }
            )
        except Exception as exc:
            fail("Nie udalo sie uruchomic lokalnego klienta Vanna.", str(exc))

        return client, marker_path

    fail("Nieobslugiwany provider Vanna.")


def train_if_needed(vn, payload: dict[str, object], marker_path: Path | None) -> None:
    bridge_cache_root = Path(str(payload.get("bridge_cache_root") or Path.cwd() / "tmp" / "vanna_cache"))
    bridge_cache_root.mkdir(parents=True, exist_ok=True)

    cache_namespace = safe_slug(str(payload.get("cache_namespace") or "default"))
    schema_hash = str(payload.get("schema_hash") or "").strip()
    current_marker = marker_path or (bridge_cache_root / f"{cache_namespace}.json")

    if schema_hash and current_marker.exists():
        try:
            marker_data = json.loads(current_marker.read_text(encoding="utf-8"))
        except Exception:
            marker_data = {}
        if marker_data.get("schema_hash") == schema_hash:
            return

    for ddl in payload.get("ddl") or []:
        if isinstance(ddl, str) and ddl.strip():
            vn.train(ddl=ddl.strip())

    for documentation in payload.get("documentation") or []:
        if isinstance(documentation, str) and documentation.strip():
            vn.train(documentation=documentation.strip())

    for example in payload.get("examples") or []:
        if not isinstance(example, dict):
            continue
        question = str(example.get("question") or "").strip()
        sql = str(example.get("sql") or "").strip()
        if question and sql:
            vn.train(question=question, sql=sql)

    if schema_hash:
        current_marker.write_text(
            json.dumps({"schema_hash": schema_hash}, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )


def main() -> None:
    payload = load_payload()
    question = str(payload.get("question") or "").strip()
    if not question:
        fail("Pytanie do Vanna AI nie moze byc puste.")

    log_buffer = io.StringIO()

    try:
        with contextlib.redirect_stdout(log_buffer):
            vn, marker_path = create_client(payload)
            train_if_needed(vn, payload, marker_path)
            sql = vn.generate_sql(question=question, allow_llm_to_see_data=False)
    except Exception as exc:
        fail("Vanna AI nie wygenerowala SQL.", str(exc))

    generated_sql = str(sql or "").strip()
    if not generated_sql:
        fail("Vanna AI zwrocila pusty SQL.")

    response: dict[str, object] = {
        "ok": True,
        "sql": generated_sql,
    }

    logs = log_buffer.getvalue().strip()
    if logs:
        response["log"] = logs

    respond(response)


if __name__ == "__main__":
    main()
