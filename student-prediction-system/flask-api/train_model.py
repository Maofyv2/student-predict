from __future__ import annotations

import json
from pathlib import Path

import joblib
import pandas as pd
from sklearn.metrics import accuracy_score, classification_report, f1_score, mean_absolute_error, mean_squared_error
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from xgboost import XGBClassifier, XGBRegressor


BASE_DIR = Path(__file__).resolve().parents[1]
DATASET_PATH = BASE_DIR / "dataset" / "student_data.csv"
MODEL_DIR = BASE_DIR / "model"
METADATA_PATH = MODEL_DIR / "model_metadata.json"

# Legacy paths kept for backward-compatibility (old /predict endpoint)
MODEL_PATH = MODEL_DIR / "xgboost_model.pkl"
LEGACY_MODEL_PATH = MODEL_DIR / "gboost_model.pkl"
ENCODER_PATH = MODEL_DIR / "label_encoder.pkl"

# Stage-specific model paths
STAGE_MODEL_PATHS = {
    "Prelim":     MODEL_DIR / "model_prelim.pkl",
    "Midterm":    MODEL_DIR / "model_midterm.pkl",
    "Semi-Final": MODEL_DIR / "model_semifinal.pkl",
    "Final":      MODEL_DIR / "model_final.pkl",
}

STAGE_ENCODER_PATHS = {
    "Prelim":     MODEL_DIR / "encoder_prelim.pkl",
    "Midterm":    MODEL_DIR / "encoder_midterm.pkl",
    "Semi-Final": MODEL_DIR / "encoder_semifinal.pkl",
    "Final":      MODEL_DIR / "encoder_final.pkl",
}

# ------------------------------------------------------------------ #
# Socio-demographic features present at every stage
# ------------------------------------------------------------------ #
SOCIO_FEATURES = [
    "attendance_rate",
    "lab_score",
    "internet_access",
    "digital_literacy",
    "household_income",
    "parental_education",
    "study_hours",
    "working_student",
]

# Forecast the next period from grades that are already available.
NEXT_PERIOD_TARGETS = {
    "Prelim": "midterm_grade",
    "Midterm": "semi_final_grade",
    "Semi-Final": "final_grade",
}
NEXT_PERIOD_MODEL_PATHS = {
    period: MODEL_DIR / f"next_period_{period.lower().replace('-', '')}.pkl"
    for period in NEXT_PERIOD_TARGETS
}
NEXT_PERIOD_FEATURE_COLUMNS = {
    "Prelim": ["prelim_grade"] + SOCIO_FEATURES,
    "Midterm": ["prelim_grade", "midterm_grade"] + SOCIO_FEATURES,
    "Semi-Final": ["prelim_grade", "midterm_grade", "semi_final_grade"] + SOCIO_FEATURES,
}

# Feature sets per stage
# The "current period computed grade" maps to the CSV column for that period.
STAGE_FEATURE_COLUMNS: dict[str, list[str]] = {
    "Prelim": [
        "computed_grade",   # <- prelim_grade from CSV
    ] + SOCIO_FEATURES,

    "Midterm": [
        "prelim_grade",
        "computed_grade",   # <- midterm_grade from CSV
    ] + SOCIO_FEATURES,

    "Semi-Final": [
        "prelim_grade",
        "midterm_grade",
        "computed_grade",   # <- semi_final_grade from CSV
    ] + SOCIO_FEATURES,

    "Final": [
        "prelim_grade",
        "midterm_grade",
        "semi_final_grade",
        "computed_grade",   # <- final_grade from CSV
    ] + SOCIO_FEATURES,
}

# Legacy feature list (for backward-compatible /predict endpoint)
FEATURE_COLUMNS = [
    "prelim_grade",
    "midterm_grade",
    "semi_final_grade",
    "final_grade",
    "attendance_rate",
    "lab_score",
    "internet_access",
    "digital_literacy",
    "household_income",
    "parental_education",
    "study_hours",
    "working_student",
]

TARGET_COLUMN = "status"


def load_training_data() -> pd.DataFrame:
    df = pd.read_csv(DATASET_PATH)
    required = FEATURE_COLUMNS + [TARGET_COLUMN]
    missing = [c for c in required if c not in df.columns]
    if missing:
        raise ValueError(f"Dataset is missing required column(s): {', '.join(missing)}")

    for col in FEATURE_COLUMNS:
        df[col] = pd.to_numeric(df[col], errors="coerce")

    df = df.dropna(subset=required).copy()
    if df.empty:
        raise ValueError("Dataset has no usable training rows after cleaning.")
    return df


def _build_xgb() -> XGBClassifier:
    return XGBClassifier(
        objective="multi:softprob",
        eval_metric="mlogloss",
        n_estimators=120,
        max_depth=3,
        learning_rate=0.08,
        subsample=0.9,
        colsample_bytree=0.9,
        random_state=42,
        verbosity=0,
    )


def train_next_period(df: pd.DataFrame, period: str) -> dict:
    """Fit a grade regressor for the period immediately after `period`."""
    features = NEXT_PERIOD_FEATURE_COLUMNS[period]
    target = NEXT_PERIOD_TARGETS[period]
    usable = df[features + [target]].apply(pd.to_numeric, errors="coerce").dropna()
    x, y = usable[features], usable[target]
    x_train, x_test, y_train, y_test = train_test_split(
        x, y, test_size=0.25, random_state=42
    )
    regressor = XGBRegressor(
        objective="reg:squarederror", n_estimators=120, max_depth=3,
        learning_rate=0.08, subsample=0.9, colsample_bytree=0.9,
        random_state=42, verbosity=0,
    )
    regressor.fit(x_train, y_train)
    prediction = regressor.predict(x_test)
    within_5_points_rate = float((abs(y_test.to_numpy() - prediction) <= 5.0).mean())
    joblib.dump(regressor, NEXT_PERIOD_MODEL_PATHS[period])
    return {
        "source_period": period,
        "target_period": target,
        "feature_columns": features,
        "training_rows": int(len(usable)),
        "test_rows": int(len(x_test)),
        "mae": round(float(mean_absolute_error(y_test, prediction)), 3),
        "rmse": round(float(mean_squared_error(y_test, prediction) ** 0.5), 3),
        "within_5_points_rate": round(within_5_points_rate, 4),
    }


def train_stage(
    df: pd.DataFrame,
    period: str,
) -> dict:
    """Train one stage model and return its metadata dict."""
    period_grade_col = {
        "Prelim":     "prelim_grade",
        "Midterm":    "midterm_grade",
        "Semi-Final": "semi_final_grade",
        "Final":      "final_grade",
    }[period]

    # Build the feature DataFrame for this stage
    stage_cols = STAGE_FEATURE_COLUMNS[period]
    df_stage = df.copy()
    # Map 'computed_grade' to the appropriate CSV column
    df_stage["computed_grade"] = df_stage[period_grade_col]

    x = df_stage[stage_cols]
    encoder = LabelEncoder()
    y = encoder.fit_transform(df_stage[TARGET_COLUMN])

    x_train, x_test, y_train, y_test = train_test_split(
        x, y, test_size=0.25, random_state=42, stratify=y
    )

    model = _build_xgb()
    model.fit(x_train, y_train)

    y_pred = model.predict(x_test)
    labels = [str(c) for c in encoder.classes_]

    report = classification_report(
        y_test,
        y_pred,
        labels=list(range(len(labels))),
        target_names=labels,
        output_dict=True,
        zero_division=0,
    )

    importance = {
        col: round(float(score), 5)
        for col, score in sorted(
            zip(stage_cols, model.feature_importances_),
            key=lambda item: item[1],
            reverse=True,
        )
    }

    # Save model + encoder
    joblib.dump(model, STAGE_MODEL_PATHS[period])
    joblib.dump(encoder, STAGE_ENCODER_PATHS[period])

    return {
        "period": period,
        "feature_columns": stage_cols,
        "classes": labels,
        "training_rows": int(len(df_stage)),
        "test_rows": int(len(x_test)),
        "accuracy": round(float(accuracy_score(y_test, y_pred)), 4),
        "weighted_f1": round(float(f1_score(y_test, y_pred, average="weighted")), 4),
        "classification_report": report,
        "feature_importance": importance,
    }


def train_and_save_model() -> dict:
    """Train all 4 stage models + one legacy full model. Returns combined metadata."""
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    df = load_training_data()

    stage_metadata: dict[str, dict] = {}
    for period in ("Prelim", "Midterm", "Semi-Final", "Final"):
        print(f"Training {period} model …")
        stage_metadata[period] = train_stage(df, period)

    next_period_metadata = {
        period: train_next_period(df, period)
        for period in NEXT_PERIOD_TARGETS
    }

    # Also train the legacy full model (for /predict backward-compat)
    encoder_legacy = LabelEncoder()
    x_legacy = df[FEATURE_COLUMNS]
    y_legacy = encoder_legacy.fit_transform(df[TARGET_COLUMN])
    x_tr, x_te, y_tr, y_te = train_test_split(
        x_legacy, y_legacy, test_size=0.25, random_state=42, stratify=y_legacy
    )
    model_legacy = _build_xgb()
    model_legacy.fit(x_tr, y_tr)
    y_pred_l = model_legacy.predict(x_te)
    labels_l = [str(c) for c in encoder_legacy.classes_]
    joblib.dump(model_legacy, MODEL_PATH)
    joblib.dump(model_legacy, LEGACY_MODEL_PATH)
    joblib.dump(encoder_legacy, ENCODER_PATH)

    # Combined metadata (backward-compat keys + stage breakdown)
    metadata = {
        "algorithm": "XGBoost Classification (Progressive Stages)",
        "feature_columns": FEATURE_COLUMNS,
        "classes": labels_l,
        "training_rows": int(len(df)),
        "test_rows": int(len(x_te)),
        "accuracy": round(float(accuracy_score(y_te, y_pred_l)), 4),
        "weighted_f1": round(float(f1_score(y_te, y_pred_l, average="weighted")), 4),
        "stage_models": stage_metadata,
        "next_period_models": next_period_metadata,
        "feature_importance": stage_metadata["Final"]["feature_importance"],
    }

    METADATA_PATH.write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    print("All models saved.")
    return metadata


if __name__ == "__main__":
    result = train_and_save_model()
    for period, info in result["stage_models"].items():
        print(f"{period}: accuracy={info['accuracy']}, f1={info['weighted_f1']}")
