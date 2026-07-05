from __future__ import annotations

import json
from pathlib import Path

import joblib
import pandas as pd
from sklearn.metrics import accuracy_score, classification_report, f1_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from xgboost import XGBClassifier


BASE_DIR = Path(__file__).resolve().parents[1]
DATASET_PATH = BASE_DIR / "dataset" / "student_data.csv"
MODEL_DIR = BASE_DIR / "model"
MODEL_PATH = MODEL_DIR / "xgboost_model.pkl"
LEGACY_MODEL_PATH = MODEL_DIR / "gboost_model.pkl"
ENCODER_PATH = MODEL_DIR / "label_encoder.pkl"
METADATA_PATH = MODEL_DIR / "model_metadata.json"

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
    missing = [column for column in FEATURE_COLUMNS + [TARGET_COLUMN] if column not in df.columns]
    if missing:
        joined = ", ".join(missing)
        raise ValueError(f"Dataset is missing required column(s): {joined}")

    for column in FEATURE_COLUMNS:
        df[column] = pd.to_numeric(df[column], errors="coerce")

    df = df.dropna(subset=FEATURE_COLUMNS + [TARGET_COLUMN]).copy()
    if df.empty:
        raise ValueError("Dataset has no usable training rows after cleaning.")
    return df


def train_and_save_model() -> dict:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    df = load_training_data()

    encoder = LabelEncoder()
    x = df[FEATURE_COLUMNS]
    y = encoder.fit_transform(df[TARGET_COLUMN])

    x_train, x_test, y_train, y_test = train_test_split(
        x,
        y,
        test_size=0.25,
        random_state=42,
        stratify=y,
    )

    model = XGBClassifier(
        objective="multi:softprob",
        eval_metric="mlogloss",
        n_estimators=120,
        max_depth=3,
        learning_rate=0.08,
        subsample=0.9,
        colsample_bytree=0.9,
        random_state=42,
    )
    model.fit(x_train, y_train)

    y_pred = model.predict(x_test)
    labels = [str(label) for label in encoder.classes_]
    report = classification_report(
        y_test,
        y_pred,
        labels=list(range(len(labels))),
        target_names=labels,
        output_dict=True,
        zero_division=0,
    )

    feature_importance = {
        column: round(float(score), 5)
        for column, score in sorted(
            zip(FEATURE_COLUMNS, model.feature_importances_),
            key=lambda item: item[1],
            reverse=True,
        )
    }

    metadata = {
        "algorithm": "XGBoost Classification",
        "feature_columns": FEATURE_COLUMNS,
        "classes": labels,
        "training_rows": int(len(df)),
        "test_rows": int(len(x_test)),
        "accuracy": round(float(accuracy_score(y_test, y_pred)), 4),
        "weighted_f1": round(float(f1_score(y_test, y_pred, average="weighted")), 4),
        "classification_report": report,
        "feature_importance": feature_importance,
    }

    joblib.dump(model, MODEL_PATH)
    joblib.dump(model, LEGACY_MODEL_PATH)
    joblib.dump(encoder, ENCODER_PATH)
    METADATA_PATH.write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    return metadata


if __name__ == "__main__":
    result = train_and_save_model()
    print(json.dumps(result, indent=2))
