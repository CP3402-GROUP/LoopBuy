from __future__ import annotations

import csv
import hashlib
import os
from pathlib import Path

import joblib
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import FeatureUnion, Pipeline


ROOT = Path(__file__).resolve().parent
DATA_PATH = ROOT / "data" / "scam_training.csv"
MODEL_PATH = Path(os.environ.get("MODEL_PATH", ROOT / "artifacts" / "scam_model.joblib"))


def load_data() -> tuple[list[str], list[int]]:
    texts: list[str] = []
    labels: list[int] = []
    with DATA_PATH.open("r", encoding="utf-8", newline="") as source:
        for row in csv.DictReader(source):
            texts.append(row["text"])
            labels.append(int(row["label"]))
    return texts, labels


def train() -> dict:
    texts, labels = load_data()
    features = FeatureUnion(
        [
            ("words", TfidfVectorizer(ngram_range=(1, 2), min_df=1, sublinear_tf=True)),
            ("chars", TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), min_df=1)),
        ]
    )
    pipeline = Pipeline(
        [
            ("features", features),
            (
                "classifier",
                LogisticRegression(
                    class_weight="balanced",
                    max_iter=1000,
                    random_state=42,
                ),
            ),
        ]
    )
    pipeline.fit(texts, labels)
    digest = hashlib.sha256(DATA_PATH.read_bytes()).hexdigest()[:12]
    artifact = {
        "pipeline": pipeline,
        "version": f"tfidf-logreg-{digest}",
        "training_rows": len(texts),
        "dataset_sha256": hashlib.sha256(DATA_PATH.read_bytes()).hexdigest(),
    }
    MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, MODEL_PATH)
    return artifact


if __name__ == "__main__":
    trained = train()
    print(f"trained {trained['version']} on {trained['training_rows']} rows")
