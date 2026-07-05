# Arellano BSIT Student Academic Performance Prediction System

This project is a PHP/MySQL dashboard with a Flask XGBoost prediction API for classifying BSIT students as `Pass`, `At-Risk`, or `Fail`.

## Start

1. Start Apache and MySQL in XAMPP.
2. Train the model:

   ```powershell
   py flask-api/train_model.py
   ```

3. Start the Flask API:

   ```powershell
   cd flask-api
   py app.py
   ```

4. Open the PHP dashboard:

   ```text
   http://localhost/student-prediction-system/php-dashboard/
   ```

## Default Login

- `admin` / `admin123`
- `advisor` / `advisor123`

## Main Modules

- Authentication for admin/advisor access
- Student profile, academic record, and survey intake
- XGBoost prediction endpoint
- Dashboard outcome distribution
- Key predictor and faculty intervention reports
- CSV report export
