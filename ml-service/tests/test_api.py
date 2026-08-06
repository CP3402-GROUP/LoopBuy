from fastapi.testclient import TestClient

from app.main import SIGNALS, app


client = TestClient(app)


def test_health_reports_model() -> None:
    response = client.get("/healthz")
    assert response.status_code == 200
    assert response.json()["model_version"].startswith("tfidf-logreg-")


def test_scam_prediction_flags_credentials() -> None:
    response = client.post(
        "/v1/scam/predict",
        json={
            "title": "Cheap phone",
            "description": "Pay immediately with gift cards and send your OTP",
            "price": 20,
            "category": "electronics",
        },
    )
    assert response.status_code == 200
    payload = response.json()
    assert payload["label"] in {"needs_review", "high_risk"}
    assert payload["score"] >= 0.45
    assert payload["reasons"]
    assert payload["risk_signal_count"] >= 2


def test_borderline_prediction_reports_no_explicit_signal() -> None:
    response = client.post(
        "/v1/scam/predict",
        json={
            "title": "Acoustic guitar with padded bag",
            "description": "Used guitar in good condition. Available to inspect in person.",
            "price": 135,
            "category": "music",
        },
    )
    assert response.status_code == 200
    payload = response.json()
    assert payload["risk_signal_count"] == 0
    assert all(reason not in payload["reasons"] for _, reason in SIGNALS)


def test_reranker_prefers_matching_candidate() -> None:
    response = client.post(
        "/v1/recommendations/rerank",
        json={
            "preference_text": "quiet mechanical keyboard for gaming",
            "candidates": [
                {"listing_id": 1, "text": "Razer mechanical gaming keyboard", "base_score": 0.4},
                {"listing_id": 2, "text": "wooden dining table", "base_score": 0.4},
            ],
            "limit": 2,
        },
    )
    assert response.status_code == 200
    assert response.json()["items"][0]["listing_id"] == 1
