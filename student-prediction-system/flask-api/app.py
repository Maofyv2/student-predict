from __future__ import annotations

import json
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from flask import Flask, jsonify, request

try:
    from flask_cors import CORS
except ImportError:
    CORS = None

from train_model import (
    ENCODER_PATH,
    FEATURE_COLUMNS,
    METADATA_PATH,
    MODEL_PATH,
    train_and_save_model,
)


app = Flask(__name__)
if CORS:
    CORS(app)
@app.get("/")
def home():
    return jsonify({
        "message": "Student Prediction API is running!"
    })

@app.after_request
def add_cors_headers(response):
    response.headers.setdefault("Access-Control-Allow-Origin", "*")
    response.headers.setdefault("Access-Control-Allow-Headers", "Content-Type")
    response.headers.setdefault("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    return response


def ensure_model_files() -> None:
    missing = [path for path in (MODEL_PATH, ENCODER_PATH) if not path.exists() or path.stat().st_size == 0]
    if missing:
        train_and_save_model()


def load_artifacts():
    ensure_model_files()
    model = joblib.load(MODEL_PATH)
    encoder = joblib.load(ENCODER_PATH)
    metadata = {}
    if METADATA_PATH.exists():
        metadata = json.loads(METADATA_PATH.read_text(encoding="utf-8"))
    return model, encoder, metadata


model, encoder, metadata = load_artifacts()


def as_number(data: dict, key: str, minimum: float | None = None, maximum: float | None = None) -> float:
    if key not in data or data[key] == "":
        raise ValueError(f"{key} is required")
    try:
        value = float(data[key])
    except (TypeError, ValueError) as exc:
        raise ValueError(f"{key} must be numeric") from exc
    if minimum is not None and value < minimum:
        raise ValueError(f"{key} must be at least {minimum:g}")
    if maximum is not None and value > maximum:
        raise ValueError(f"{key} must be at most {maximum:g}")
    return value


def build_feature_row(data: dict) -> dict:
    limits = {
    "prelim_grade": (0, 100),
    "midterm_grade": (0, 100),
    "semi_final_grade": (0, 100),
    "final_grade": (0, 100),
    "attendance_rate": (0, 100),
    "lab_score": (0, 100),
    "internet_access": (0, 1),
    "digital_literacy": (1, 5),
    "household_income": (0, None),
    "parental_education": (1, 4),
    "study_hours": (0, 80),
    "working_student": (0, 1),
}
    row = {}
    for key in FEATURE_COLUMNS:
        minimum, maximum = limits[key]
        row[key] = as_number(data, key, minimum, maximum)
    return row


def risk_factors(row: dict) -> list[str]:
    factors = []
    if row["attendance_rate"] < 80:
        factors.append("Attendance below target")
    if row["lab_score"] < 75:
        factors.append("Technical laboratory score needs support")
    if row["internet_access"] == 0:
        factors.append("Limited internet access")
    if row["digital_literacy"] <= 2:
        factors.append("Low digital literacy rating")
    if row["study_hours"] < 6:
        factors.append("Low weekly study hours")
    if row["working_student"] == 1:
        factors.append("Working student schedule load")
    return factors


def recommendation(status: str, factors: list[str]) -> str:
    if status == "Fail":
        return "Immediate advising, laboratory remediation, and attendance monitoring are recommended."
    if status == "At-Risk":
        if factors:
            return "Create an intervention plan focused on " + ", ".join(factors[:3]).lower() + "."
        return "Schedule academic advising and monitor the next assessment period closely."
    return "Maintain current support and continue regular progress monitoring."


@app.get("/health")
def health():
    return jsonify(
        {
            "status": "online",
            "model": metadata.get("algorithm", "XGBoost Classification"),
            "accuracy": metadata.get("accuracy"),
            "weighted_f1": metadata.get("weighted_f1"),
            "classes": metadata.get("classes", []),
        }
    )


@app.get("/metrics")
def metrics():
    return jsonify(metadata)


@app.post("/reload-model")
def reload_model():
    global model, encoder, metadata
    metadata = train_and_save_model()
    model, encoder, metadata = load_artifacts()
    return jsonify({"status": "reloaded", "metadata": metadata})


@app.post("/predict")
def predict():
    data = request.get_json(silent=True) or {}
    try:
        row = build_feature_row(data)
    except ValueError as exc:
        return jsonify({"error": str(exc)}), 422

    frame = pd.DataFrame([row], columns=FEATURE_COLUMNS)
    predicted_class = model.predict(frame)
    probabilities = model.predict_proba(frame)[0]
    status = str(encoder.inverse_transform(predicted_class)[0])
    confidence = float(np.max(probabilities))
    factors = risk_factors(row)

    return jsonify(
        {
            "prediction": status,
            "confidence": round(confidence, 4),
            "recommendation": recommendation(status, factors),
            "risk_factors": factors,
            "probabilities": {
                str(label): round(float(probabilities[index]), 4)
                for index, label in enumerate(encoder.classes_)
            },
            "feature_importance": metadata.get("feature_importance", {}),
        }
    )


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=False)
