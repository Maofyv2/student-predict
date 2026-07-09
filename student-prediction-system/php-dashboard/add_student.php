<?php
include('db.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $school_no = $_POST['school_no'];
    $year_level = $_POST['year_level'];
    $section = $_POST['section'];
    $gender = $_POST['gender'];

    $query = "INSERT INTO tbl_students (student_no, full_name, year_level, section, gender) 
              VALUES ('$school_no', '$fullname', '$year_level', '$section', '$gender')";

    if (mysqli_query($conn, $query)) {
        header("Location: students.php");
        exit();
    } else {
        echo "<script>alert('Error adding student: " . mysqli_error($conn) . "');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student - Prediction System</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-image: url('bg.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            padding: 30px 35px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
        }

        .form-header {
            margin-bottom: 25px;
            text-align: center;
        }

        .form-header h2 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 11px 16px;
            font-size: 15px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background-color: #fff;
            color: #334155;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background-color: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background-color: #2563eb;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }
    </style>
</head>

<body>

    <div class="form-container">
        <div class="form-header">
            <h2>Add New Student</h2>
            <p>Fill in the required information below.</p>
        </div>

        <form action="" method="POST">
            <div class="form-group">
                <label for="fullname">Fullname</label>
                <input type="text" id="fullname" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz"
                    required>
            </div>

            <div class="form-group">
                <label for="school_no">School No.</label>
                <input type="text" id="school_no" name="school_no" class="form-control" placeholder="e.g. 2026-01234"
                    required>
            </div>

            <div class="form-group">
                <label for="year_level">Year Level</label>
                <select id="year_level" name="year_level" class="form-control" required>
                    <option value="" disabled selected>Select Year Level</option>
                    <option value="1st Year">1st Year</option>
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
            </div>

            <div class="form-group">
                <label for="section">Section</label>
                <select id="section" name="section" class="form-control" required>
                    <option value="" disabled selected>Select Section</option>
                    <option value="BSIT 1">BSIT 1</option>
                    <option value="BSIT 2">BSIT 2</option>
                    <option value="BSIT 3">BSIT 3</option>
                    <option value="BSIT 4">BSIT 4</option>
                    <option value="BSIT 5">BSIT 5</option>
                    <option value="BSIT 6">BSIT 6</option>
                    <option value="BSIT 7">BSIT 7</option>
                    <option value="BSIT 8">BSIT 8</option>
                    <option value="BSIT 9">BSIT 9</option>
                    <option value="BSIT 10">BSIT 10</option>
                </select>
            </div>

            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender" name="gender" class="form-control" required>
                    <option value="" disabled selected>Select Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>

            <div class="btn-container">
                <a href="students.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
        </form>
    </div>

</body>

</html>