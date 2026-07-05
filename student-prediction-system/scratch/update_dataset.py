import csv
import random
from pathlib import Path

DATASET_PATH = Path("c:/xampp/htdocs/student-prediction-system/dataset/student_data.csv")

headers = [
    "prelim_grade", "midterm_grade", "semi_final_grade", "final_grade",
    "attendance_rate", "lab_score", "internet_access", "digital_literacy",
    "household_income", "parental_education", "study_hours", "working_student",
    "status"
]

rows = []
for _ in range(1500):
    # Pass pattern
    if random.random() < 0.6:
        prelim = random.uniform(82, 100)
        midterm = random.uniform(82, 100)
        semi = random.uniform(80, 100)
        final = random.uniform(80, 100)
        attendance = random.uniform(85, 100)
        lab = random.uniform(80, 100)
        status = "Pass"
    # At-Risk pattern
    elif random.random() < 0.8:
        prelim = random.uniform(70, 85)
        midterm = random.uniform(70, 85)
        semi = random.uniform(65, 80)
        final = random.uniform(65, 80)
        attendance = random.uniform(70, 90)
        lab = random.uniform(70, 90)
        status = "At-Risk"
    # Fail pattern
    else:
        prelim = random.uniform(50, 75)
        midterm = random.uniform(50, 75)
        semi = random.uniform(40, 70)
        final = random.uniform(40, 70)
        attendance = random.uniform(40, 75)
        lab = random.uniform(40, 80)
        status = "Fail"
    
    rows.append([
        round(prelim, 2), round(midterm, 2), round(semi, 2), round(final, 2),
        round(attendance, 2), round(lab, 2),
        random.choice([0, 1]), random.randint(1, 5),
        random.randint(10000, 80000), random.randint(1, 4),
        round(random.uniform(2, 20), 1), random.choice([0, 1]),
        status
    ])

with open(DATASET_PATH, "w", newline="") as f:
    writer = csv.writer(f)
    writer.writerow(headers)
    writer.writerows(rows)

print(f"Dataset updated with {len(rows)} rows and 12 features at {DATASET_PATH}")
