from fastapi.testclient import TestClient

from app.main import app


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
