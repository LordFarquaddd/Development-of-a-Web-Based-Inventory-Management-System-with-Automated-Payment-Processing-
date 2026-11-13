<?php
  $db = mysqli_connect('localhost', 'root', '', 'class_record');

if (isset($_POST['insert_btn'])) {

    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $course = $_POST['course'];
    $student_num = $_POST['student_num'];

    mysqli_query($db, "INSERT INTO `student` (`first_name`, `last_name`, `course`, `student_num`) 
    VALUES ('$first_name', '$last_name', '$course', '$student_num')");

    header("Location: index.php");
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insert Student</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 40px 20px;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background: #FCE4EC;
            color: #3B2A30;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            width: 100%;
            max-width: 480px;
            background: #FFFFFF;
            border-radius: 16px;
            padding: 36px 32px;
            box-shadow: 0 10px 30px rgba(194, 24, 91, 0.12);
        }

        .card h1 {
            margin: 0 0 4px;
            font-size: 26px;
            font-weight: 700;
        }

        .form .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form .field {
            margin-bottom: 18px;
        }

        .form label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        .form input,
        .form select {
            display: block;
            width: 100%;
            padding: 11px 12px;
            font-family: inherit;
            font-size: 15px;
            color: #3B2A30;
            background: #FFF8FA;
            border: 1.5px solid #F0C6D4;
            border-radius: 8px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form input:focus,
        .form select:focus {
            outline: none;
            border-color: ##C2185B;
            box-shadow: 0 0 0 4px #c2185b40;
        }

        .form button {
            display: block;
            width: 100%;
            margin-top: 8px;
            padding: 13px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            color: #fff;
            background: #C2185B;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
        }

        .form button:hover {
            background: #9C1349;
        }

        .form button:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px #c2185b40;
        }

        .back-link {
            display: block;
            margin-top: 18px;
            text-align: center;
            font-size: 14px;
            color: #C2185B;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

    </style>
</head>
<body>
    <div class="card">
        <h1>Add a student</h1>

        <form action="" method="POST" class="form">

            <div class="row">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>

                <div class="field">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
            </div>

            <div class="field">
                <label for="course">Course</label>
                <select id="course" name="course" required>
                    <option value="">Select a course</option>
                    <option value="BS IT">BS IT</option>
                    <option value="BS CS">BS CS</option>
                    <option value="BS EMC">BS EMC</option>
                    <option value="BS BA">BS BA</option>
                    <option value="BS A">BS A</option>
                    <option value="BS PT">BS PT</option>
                    <option value="BS TM">BS TM</option>
                    <option value="BSNED">B SNED</option>
                </select>
            </div>

            <div class="field">
                <label for="student_num">Student number</label>
                <input type="text" id="student_num" name="student_num" required>
            </div>

            <button type="submit" name="insert_btn">Save student</button>
        </form>
    </div>
</body>
</html>