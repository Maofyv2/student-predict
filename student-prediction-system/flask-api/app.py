from __future__ import annotations
import os
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
    STAGE_ENCODER_PATHS,
    STAGE_FEATURE_COLUMNS,
    STAGE_MODEL_PATHS,
    SOCIO_FEATURES,
    train_and_save_model,
)

app = Flask(__name__)
if CORS:
    CORS(app)


@app.after_request
def add_cors_headers(response):
    response.headers.setdefault("Access-Control-Allow-Origin", "*")
    response.headers.setdefault("Access-Control-Allow-Headers", "Content-Type")
    response.headers.setdefault("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    return response


# ------------------------------------------------------------------ #
# Artifact loading
# ------------------------------------------------------------------ #

def ensure_model_files() -> None:
    missing_legacy = [p for p in (MODEL_PATH, ENCODER_PATH) if not p.exists() or p.stat().st_size == 0]
    missing_stage  = [p for p in STAGE_MODEL_PATHS.values() if not p.exists() or p.stat().st_size == 0]
    if missing_legacy or missing_stage:
        train_and_save_model()


def load_artifacts():
    ensure_model_files()
    model   = joblib.load(MODEL_PATH)
    encoder = joblib.load(ENCODER_PATH)
    metadata = {}
    if METADATA_PATH.exists():
        metadata = json.loads(METADATA_PATH.read_text(encoding="utf-8"))

    stage_models: dict   = {}
    stage_encoders: dict = {}
    for period, path in STAGE_MODEL_PATHS.items():
        if path.exists():
            stage_models[period]   = joblib.load(path)
            stage_encoders[period] = joblib.load(STAGE_ENCODER_PATHS[period])

    return model, encoder, metadata, stage_models, stage_encoders


model, encoder, metadata, stage_models, stage_encoders = load_artifacts()


# ------------------------------------------------------------------ #
# Utility helpers
# ------------------------------------------------------------------ #

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


def optional_number(data: dict, key: str, minimum: float | None = None, maximum: float | None = None) -> float | None:
    """Return None if key is missing or empty; otherwise parse and validate."""
    if key not in data or data[key] is None or str(data[key]).strip() == "":
        return None
    try:
        value = float(data[key])
    except (TypeError, ValueError) as exc:
        raise ValueError(f"{key} must be numeric") from exc
    if minimum is not None and value < minimum:
        raise ValueError(f"{key} must be at least {minimum:g}")
    if maximum is not None and value > maximum:
        raise ValueError(f"{key} must be at most {maximum:g}")
    return value


def risk_factors(row: dict) -> list[str]:
    factors = []
    if row.get("attendance_rate", 100) < 80:
        factors.append("Attendance below target")
    if row.get("lab_score", 100) < 75:
        factors.append("Technical laboratory score needs support")
    if row.get("internet_access", 1) == 0:
        factors.append("Limited internet access")
    if row.get("digital_literacy", 3) <= 2:
        factors.append("Low digital literacy rating")
    if row.get("study_hours", 10) < 6:
        factors.append("Low weekly study hours")
    if row.get("working_student", 0) == 1:
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


# ------------------------------------------------------------------ #
# Legacy feature builder (for /predict backward-compat)
# ------------------------------------------------------------------ #

def build_feature_row(data: dict) -> dict:
    limits = {
        "prelim_grade":      (0, 100),
        "midterm_grade":     (0, 100),
        "semi_final_grade":  (0, 100),
        "final_grade":       (0, 100),
        "attendance_rate":   (0, 100),
        "lab_score":         (0, 100),
        "internet_access":   (0, 1),
        "digital_literacy":  (1, 5),
        "household_income":  (0, None),
        "parental_education":(1, 4),
        "study_hours":       (0, 80),
        "working_student":   (0, 1),
    }
    row = {}
    for key in FEATURE_COLUMNS:
        minimum, maximum = limits[key]
        row[key] = as_number(data, key, minimum, maximum)
    return row


# ------------------------------------------------------------------ #
# Progressive feature builder
# ------------------------------------------------------------------ #

PERIOD_PREVIOUS: dict[str, list[str]] = {
    "Prelim":     [],
    "Midterm":    ["prelim_grade"],
    "Semi-Final": ["prelim_grade", "midterm_grade"],
    "Final":      ["prelim_grade", "midterm_grade", "semi_final_grade"],
}

GRADE_LIMITS = (0.0, 100.0)
SOCIO_LIMITS: dict[str, tuple] = {
    "attendance_rate":   (0, 100),
    "lab_score":         (0, 100),
    "internet_access":   (0, 1),
    "digital_literacy":  (1, 5),
    "household_income":  (0, None),
    "parental_education":(1, 4),
    "study_hours":       (0, 80),
    "working_student":   (0, 1),
}


def build_progressive_row(data: dict, period: str) -> dict:
    """
    Build the feature dict for the stage model.
    Raises ValueError for invalid/missing required fields.
    """
    row: dict = {}

    # Previous period grades (required for that stage)
    for prev_grade in PERIOD_PREVIOUS[period]:
        row[prev_grade] = as_number(data, prev_grade, *GRADE_LIMITS)

    # Current period computed grade (required)
    row["computed_grade"] = as_number(data, "computed_grade", *GRADE_LIMITS)

    # Socio-demographic features (all required)
    for feat, (lo, hi) in SOCIO_LIMITS.items():
        row[feat] = as_number(data, feat, lo, hi)

    return row


# ------------------------------------------------------------------ #
# Routes — standard
# ------------------------------------------------------------------ #

@app.get("/")
def home():
    return jsonify({"message": "Student Prediction API is running! (Progressive Stages enabled)"})


@app.get("/health")
def health():
    stage_info = {
        period: {
            "loaded": period in stage_models,
            "accuracy": metadata.get("stage_models", {}).get(period, {}).get("accuracy"),
        }
        for period in ("Prelim", "Midterm", "Semi-Final", "Final")
    }
    return jsonify({
        "status": "online",
        "model": metadata.get("algorithm", "XGBoost Classification"),
        "accuracy": metadata.get("accuracy"),
        "weighted_f1": metadata.get("weighted_f1"),
        "classes": metadata.get("classes", []),
        "stage_models": stage_info,
    })


@app.get("/metrics")
def metrics():
    return jsonify(metadata)


@app.post("/reload-model")
def reload_model():
    global model, encoder, metadata, stage_models, stage_encoders
    metadata = train_and_save_model()
    model, encoder, metadata, stage_models, stage_encoders = load_artifacts()
    return jsonify({"status": "reloaded", "metadata": metadata})


# ------------------------------------------------------------------ #
# Legacy /predict endpoint (backward-compatible)
# ------------------------------------------------------------------ #

@app.post("/predict")
def predict():
    data = request.get_json(silent=True) or {}
    try:
        row = build_feature_row(data)
    except ValueError as exc:
        return jsonify({"error": str(exc)}), 422

    frame = pd.DataFrame([row], columns=FEATURE_COLUMNS)
    predicted_class  = model.predict(frame)
    probabilities    = model.predict_proba(frame)[0]
    status           = str(encoder.inverse_transform(predicted_class)[0])
    confidence       = float(np.max(probabilities))
    factors          = risk_factors(row)

    return jsonify({
        "prediction":        status,
        "confidence":        round(confidence, 4),
        "recommendation":    recommendation(status, factors),
        "risk_factors":      factors,
        "probabilities":     {
            str(label): round(float(probabilities[idx]), 4)
            for idx, label in enumerate(encoder.classes_)
        },
        "feature_importance": metadata.get("feature_importance", {}),
    })


# ------------------------------------------------------------------ #
# NEW: /predict-progressive endpoint
# ------------------------------------------------------------------ #

@app.post("/predict-progressive")
def predict_progressive():
    data   = request.get_json(silent=True) or {}
    period = str(data.get("grading_period", "")).strip()

    if period not in STAGE_MODEL_PATHS:
        return jsonify({
            "error": f"grading_period must be one of: {', '.join(STAGE_MODEL_PATHS.keys())}"
        }), 422

    if period not in stage_models:
        return jsonify({"error": f"Stage model for '{period}' is not loaded. Please retrain."}), 503

    try:
        row = build_progressive_row(data, period)
    except ValueError as exc:
        return jsonify({"error": str(exc)}), 422

    stage_feats    = STAGE_FEATURE_COLUMNS[period]
    frame          = pd.DataFrame([row], columns=stage_feats)
    s_model        = stage_models[period]
    s_encoder      = stage_encoders[period]

    predicted_class = s_model.predict(frame)
    probabilities   = s_model.predict_proba(frame)[0]
    status          = str(s_encoder.inverse_transform(predicted_class)[0])
    confidence      = float(np.max(probabilities))
    factors         = risk_factors(row)

    stage_meta = metadata.get("stage_models", {}).get(period, {})

    return jsonify({
        "prediction":         status,
        "predicted_grade":    round(row["computed_grade"], 2),
        "grading_period":     period,
        "confidence":         round(confidence, 4),
        "recommendation":     recommendation(status, factors),
        "risk_factors":       factors,
        "probabilities":      {
            str(label): round(float(probabilities[idx]), 4)
            for idx, label in enumerate(s_encoder.classes_)
        },
        "feature_importance": stage_meta.get("feature_importance", {}),
        "model_accuracy":     stage_meta.get("accuracy"),
        "model_f1":           stage_meta.get("weighted_f1"),
    })


# ------------------------------------------------------------------ #
# Main
# ------------------------------------------------------------------ #

if __name__ == "__main__":
    app.run(
        host="0.0.0.0",
        port=int(os.environ.get("PORT", 5000)),
        debug=False,
    )
