import random
import json

first_names = ["James", "Mary", "Robert", "Patricia", "John", "Jennifer", "Michael", "Linda", "David", "Elizabeth", 
               "William", "Barbara", "Richard", "Susan", "Joseph", "Jessica", "Thomas", "Sarah", "Charles", "Karen",
               "Christopher", "Nancy", "Daniel", "Lisa", "Matthew", "Betty", "Anthony", "Margaret", "Mark", "Sandra",
               "Donald", "Ashley", "Steven", "Kimberly", "Paul", "Emily", "Andrew", "Donna", "Joshua", "Michelle",
               "Kenneth", "Dorothy", "Kevin", "Carol", "Brian", "Amanda", "George", "Melissa", "Timothy", "Deborah"]

last_names = ["Smith", "Johnson", "Williams", "Brown", "Jones", "Garcia", "Miller", "Davis", "Rodriguez", "Martinez",
              "Hernandez", "Lopez", "Gonzalez", "Wilson", "Anderson", "Thomas", "Taylor", "Moore", "Jackson", "Martin",
              "Lee", "Perez", "Thompson", "White", "Harris", "Sanchez", "Clark", "Ramirez", "Lewis", "Robinson",
              "Walker", "Young", "Allen", "King", "Wright", "Scott", "Torres", "Nguyen", "Hill", "Flores",
              "Green", "Adams", "Nelson", "Baker", "Hall", "Rivera", "Campbell", "Mitchell", "Carter", "Roberts"]

sections = ["A", "B", "C", "D"]
year_levels = ["1st Year", "2nd Year", "3rd Year", "4th Year"]
genders = ["Male", "Female"]
scholarships = ["None", "Full Scholarship", "Partial Scholarship", "DOST Scholarship"]

sql_file = "c:/xampp/htdocs/student-prediction-system/database/seed_students.sql"

with open(sql_file, "w") as f:
    f.write("USE student_prediction_system;\n\n")
    f.write("SET FOREIGN_KEY_CHECKS = 0;\n")
    f.write("TRUNCATE TABLE tbl_predictions;\n")
    f.write("TRUNCATE TABLE tbl_academic_records;\n")
    f.write("TRUNCATE TABLE tbl_surveys;\n")
    f.write("TRUNCATE TABLE tbl_students;\n")
    f.write("SET FOREIGN_KEY_CHECKS = 1;\n\n")
    
    for i in range(1, 101):
        first = random.choice(first_names)
        last = random.choice(last_names)
        full_name = f"{first} {last}"
        student_no = f"2024-{10000 + i}"
        year = random.choice(year_levels)
        section = random.choice(sections)
        gender = random.choice(genders)
        income = random.randint(10000, 65000)
        parent_edu = random.randint(1, 5)
        scholarship = random.choice(scholarships)
        working = random.choice([0, 1])
        
        f.write(f"INSERT INTO tbl_students (id, student_no, full_name, year_level, section, gender, household_income, parental_education, scholarship_status, working_student) ")
        f.write(f"VALUES ({i}, '{student_no}', '{full_name}', '{year}', '{section}', '{gender}', {income}, {parent_edu}, '{scholarship}', {working});\n")
        
        # Generate Survey Data
        internet = random.choice([0, 1])
        digital = random.randint(1, 5)
        device = random.choice(["Laptop", "Smartphone", "Desktop", "Tablet", "Laptop & Smartphone"])
        study_hours = round(random.uniform(2, 20), 1)
        f.write(f"INSERT INTO tbl_surveys (student_id, internet_access, digital_literacy, device_availability, study_hours) VALUES ({i}, {internet}, {digital}, '{device}', {study_hours});\n")
        
        # Generate Academic Records (including SF and Final)
        if i % 3 == 0: # Pass
            prelim = round(random.uniform(85, 98), 2)
            midterm = round(random.uniform(85, 98), 2)
            semifinal = round(random.uniform(85, 98), 2)
            final = round(random.uniform(85, 98), 2)
            attendance = round(random.uniform(90, 100), 2)
            status = "Pass"
            conf = round(random.uniform(0.8, 0.99), 4)
            rec = "Keep up the excellent work! Participate in advanced workshops."
            factors = "High attendance, Strong academic performance"
        elif i % 3 == 1: # At-Risk
            prelim = round(random.uniform(75, 84), 2)
            midterm = round(random.uniform(75, 84), 2)
            semifinal = round(random.uniform(70, 80), 2)
            final = round(random.uniform(70, 80), 2)
            attendance = round(random.uniform(75, 89), 2)
            status = "At-Risk"
            conf = round(random.uniform(0.6, 0.85), 4)
            rec = "Attend peer tutoring and improve study habits."
            factors = "Fluctuating grades, Moderate attendance"
        else: # Fail
            prelim = round(random.uniform(60, 74), 2)
            midterm = round(random.uniform(60, 74), 2)
            semifinal = round(random.uniform(50, 70), 2)
            final = round(random.uniform(50, 70), 2)
            attendance = round(random.uniform(50, 74), 2)
            status = "Fail"
            conf = round(random.uniform(0.7, 0.95), 4)
            rec = "Urgent intervention required. Consult with advisor immediately."
            factors = "Low academic scores, Poor attendance rate"

        lab = round(random.uniform(60, 100), 2)
        f.write(f"INSERT INTO tbl_academic_records (id, student_id, academic_year, semester, prelim_grade, midterm_grade, semi_final_grade, final_grade, attendance_rate, lab_score) ")
        f.write(f"VALUES ({i}, {i}, '2023-2024', '1st Semester', {prelim}, {midterm}, {semifinal}, {final}, {attendance}, {lab});\n")

        # Generate Prediction
        payload = json.dumps({
            "prelim_grade": prelim, "midterm_grade": midterm, 
            "semi_final_grade": semifinal, "final_grade": final,
            "attendance_rate": attendance, "lab_score": lab,
            "internet_access": internet, "digital_literacy": digital, 
            "household_income": income, "working_student": working
        })
        f.write(f"INSERT INTO tbl_predictions (student_id, academic_record_id, predicted_status, confidence, recommendation, risk_factors, feature_payload) ")
        f.write(f"VALUES ({i}, {i}, '{status}', {conf}, '{rec}', '{factors}', '{payload}');\n\n")

print(f"Generated 100 students with SF and Final grades in {sql_file}")
