from __future__ import annotations

import os
import re
from pathlib import Path
from typing import Literal

import joblib
from fastapi import FastAPI
from pydantic import BaseModel, Field, field_validator
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity


MODEL_PATH = Path(
    os.environ.get(
        "MODEL_PATH",
        Path(__file__).resolve().parents[1] / "artifacts" / "scam_model.joblib",
    )
)
if not MODEL_PATH.exists():
    from train import train

    train()
artifact = joblib.load(MODEL_PATH)
scam_pipeline = artifact["pipeline"]

app = FastAPI(title="LoopBuy ML", version="1.0.0")

SIGNALS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"\b(gift\s*card|voucher|crypto(?:currency)?)\b", re.I), "Requests a high-risk payment method"),
    (re.compile(r"\b(otp|cvv|password|verification code|bank details)\b", re.I), "Requests sensitive credentials"),
    (re.compile(r"\b(pay|payment|deposit|transfer)\b.{0,30}\b(now|immediately|first|advance)\b", re.I), "Pressures the buyer to pay in advance"),
    (re.compile(r"\b(short(?:ened)? link|scan (?:the )?qr|outside (?:the )?platform)\b", re.I), "Moves the transaction outside trusted marketplace flows"),
    (re.compile(r"\b(no viewing|cannot provide (?:more )?photos|overseas seller)\b", re.I), "Avoids normal item verification"),
]


class ScamRequest(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    description: str = Field(default="", max_length=10_000)
    price: float = Field(ge=0, le=100_000_000)
    category: str = Field(default="", max_length=100)


class ScamResponse(BaseModel):
    score: float
    label: Literal["low_risk", "needs_review", "high_risk"]
    reasons: list[str]
    risk_signal_count: int = Field(ge=0, le=len(SIGNALS))
    model_version: str


class Candidate(BaseModel):
    listing_id: int = Field(gt=0)
    text: str = Field(min_length=1, max_length=20_000)
    base_score: float = Field(default=0, ge=-1, le=1)


class RerankRequest(BaseModel):
    preference_text: str = Field(min_length=1, max_length=20_000)
    candidates: list[Candidate] = Field(min_length=1, max_length=200)
    limit: int = Field(default=20, ge=1, le=100)

    @field_validator("preference_text")
    @classmethod
    def non_blank_preference(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("preference_text cannot be blank")
        return value


class RankedCandidate(BaseModel):
    listing_id: int
    score: float


class RerankResponse(BaseModel):
    items: list[RankedCandidate]
    model_version: str = "tfidf-content-reranker-v1"


@app.get("/healthz")
def health() -> dict:
    return {
        "status": "ok",
        "model_version": artifact["version"],
        "training_rows": artifact["training_rows"],
    }


@app.post("/v1/scam/predict", response_model=ScamResponse)
def predict_scam(request: ScamRequest) -> ScamResponse:
    text = f"{request.title}\n{request.description}\ncategory {request.category}\nprice {request.price:.2f}"
    probability = float(scam_pipeline.predict_proba([text])[0][1])
    reasons = [reason for pattern, reason in SIGNALS if pattern.search(text)]
    risk_signal_count = len(reasons)

    # Lexical signals are deliberately additive: the small bundled dataset is a
    # transparent baseline, not enough evidence to auto-ban a seller.
    adjusted = min(1.0, probability + min(0.35, 0.12 * len(reasons)))
    if adjusted >= 0.78:
        label: Literal["low_risk", "needs_review", "high_risk"] = "high_risk"
    elif adjusted >= 0.45:
        label = "needs_review"
    else:
        label = "low_risk"

    if not reasons and label != "low_risk":
        reasons.append("Text pattern differs from ordinary local marketplace listings")
    return ScamResponse(
        score=round(adjusted, 6),
        label=label,
        reasons=reasons,
        risk_signal_count=risk_signal_count,
        model_version=artifact["version"],
    )


@app.post("/v1/recommendations/rerank", response_model=RerankResponse)
def rerank(request: RerankRequest) -> RerankResponse:
    documents = [request.preference_text] + [candidate.text for candidate in request.candidates]
    vectorizer = TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True, max_features=10_000)
    matrix = vectorizer.fit_transform(documents)
    similarities = cosine_similarity(matrix[0:1], matrix[1:]).reshape(-1)

    ranked: list[RankedCandidate] = []
    for candidate, text_score in zip(request.candidates, similarities, strict=True):
        normalized_base = (candidate.base_score + 1.0) / 2.0
        score = 0.7 * float(text_score) + 0.3 * normalized_base
        ranked.append(RankedCandidate(listing_id=candidate.listing_id, score=round(score, 6)))

    ranked.sort(key=lambda item: (-item.score, item.listing_id))
    return RerankResponse(items=ranked[: request.limit])
