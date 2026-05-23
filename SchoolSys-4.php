<?php
/**
 * CAMPUS CORE - UNIFIED SYSTEM
 * Integrated Features: Admin, Dean, Finance, Teacher, Student, Cashier, and RECORDS
 */
// --- 1. DATABASE SETUP & AUTO-PATCHER ---
$db_host = 'localhost';
$db_name = 'school_system_db';
$db_user = 'root';
$db_pass = '';
try {
    $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `$db_name` ");
    
    // Ensure all tables exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            firstname VARCHAR(50), lastname VARCHAR(50), email VARCHAR(100) UNIQUE,
            username VARCHAR(50) UNIQUE, password VARCHAR(255),
            role ENUM('student', 'teacher', 'cashier', 'finance', 'dean', 'records', 'admin') DEFAULT 'student',
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            course VARCHAR(150),
            address TEXT, birthdate DATE, photo VARCHAR(255) DEFAULT 'default.png'
        );
        CREATE TABLE IF NOT EXISTS subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_code VARCHAR(20), subject_title VARCHAR(100),
            units INT, teacher_id INT, sy VARCHAR(20), sem VARCHAR(20),
            course VARCHAR(150), schedule VARCHAR(100)
        );
        CREATE TABLE IF NOT EXISTS enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT, subject_id INT,
            prelim FLOAT DEFAULT 0, midterm FLOAT DEFAULT 0, final FLOAT DEFAULT 0,
            remarks VARCHAR(50) DEFAULT 'No Grade'
        );
        CREATE TABLE IF NOT EXISTS payments (
			id INT AUTO_INCREMENT PRIMARY KEY,
			student_id INT, 
			amount DECIMAL(10,2), 
			receipt_no VARCHAR(50),
			sy VARCHAR(20), 
			sem VARCHAR(20), 
			pay_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			received_by INT
        );
        CREATE TABLE IF NOT EXISTS fee_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fee_name VARCHAR(100), 
            fee_type ENUM('Tuition', 'Misc', 'Lab', 'Other') DEFAULT 'Tuition',
            amount DECIMAL(10,2), sy VARCHAR(20), sem VARCHAR(20),
            student_id INT DEFAULT NULL
        );
    ");

    // DYNAMIC AUTO-PATCHER: Forces missing columns into existing tables without deleting data
    try { $pdo->exec("ALTER TABLE users ADD COLUMN course VARCHAR(150) AFTER status"); } catch (PDOException $e) { /* Ignore if exists */ }
    try { $pdo->exec("ALTER TABLE subjects ADD COLUMN course VARCHAR(150) AFTER sem"); } catch (PDOException $e) { /* Ignore if exists */ }

    // Seed Admin if not exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'admin'"); $stmt->execute();
    if (!$stmt->fetch()) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status) VALUES ('System', 'Admin', 'admin', ?, 'admin', 'approved')")->execute([$hash]);
    }
} catch (PDOException $e) { die("System Error: " . $e->getMessage()); }

session_start();
$msg = "";
$view = $_GET['view'] ?? 'login';

// Fetch Session Messages (Post-Redirect-Get pattern to avoid ghost errors)
if (isset($_SESSION['sys_msg'])) {
    $msg = $_SESSION['sys_msg'];
    unset($_SESSION['sys_msg']);
}

// Fetch current user data if logged in
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$_SESSION['user_id']]);
    $currentUser = $uStmt->fetch();
}

// --- 2. PHP ACTION HANDLERS ---
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit();
}

// Login Logic
if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?"); $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        if ($user['status'] == 'approved') {
            $_SESSION['user_id'] = $user['id']; $_SESSION['role'] = $user['role']; $_SESSION['name'] = $user['firstname']." ".$user['lastname'];
            header("Location: " . $_SERVER['PHP_SELF']); exit();
        } else { $msg = "<div class='alert alert-warning'>Your account is currently: " . strtoupper($user['status']) . "</div>"; }
    } else { $msg = "<div class='alert alert-danger'>Invalid credentials.</div>"; }
}

// STUDENT ONLY REGISTRATION LOGIC
if (isset($_POST['register_user'])) {
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $course = $_POST['course'] ?? 'Not Set';
    
    try {
        // Hardcoded role to 'student'
        $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, email, username, password, role, status, course) VALUES (?, ?, ?, ?, ?, 'student', 'pending', ?)");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['email'], $_POST['user'], $hash, $course]);
        
        // Success: Set session message and redirect to prevent form resubmission on refresh
        $_SESSION['sys_msg'] = "<div class='alert alert-success'>Registration successful! Wait for Admin approval.</div>";
        header("Location: ?view=login");
        exit();

    } catch (PDOException $e) { 
        // Real Error Handling: Only show "taken" if it's a true 1062 duplicate key error
        if ($e->errorInfo[1] == 1062) {
            $msg = "<div class='alert alert-danger'>Username or Email is already taken. Please try another.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Database Error: " . $e->getMessage() . "</div>";
        }
    }
}

// RECORDS ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'records') {
    if (isset($_POST['update_student_profile'])) {
        $photo_query = "";
        if(!empty($_FILES['photo']['name'])) {
            $photo_name = time() . "_" . $_FILES['photo']['name'];
			if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            move_uploaded_file($_FILES['photo']['tmp_name'], "uploads/" . $photo_name);
            $photo_query = ", photo='$photo_name'";
        }
        $stmt = $pdo->prepare("UPDATE users SET firstname=?, lastname=?, birthdate=?, address=? $photo_query WHERE id=?");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['bdate'], $_POST['addr'], $_POST['sid']]);
        $msg = "<div class='alert alert-success'>Information updated.</div>";
    }
    if (isset($_POST['records_update_grade'])) {
        $stmt = $pdo->prepare("UPDATE enrollments SET prelim=?, midterm=?, final=?, remarks=? WHERE id=?");
        $stmt->execute([$_POST['p'], $_POST['m'], $_POST['f'], $_POST['r'], $_POST['eid']]);
        $msg = "<div class='alert alert-success'>Academic records updated.</div>";
    }
}

// CASHIER ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'cashier') {
    if (isset($_POST['process_payment'])) {
        $receipt = "RCPT-" . time();
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, receipt_no, sy, sem, received_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['sid'], $_POST['amt'], $receipt, $_POST['sy'], $_POST['sem'], $_SESSION['user_id']]);
        $msg = "<div class='alert alert-success'>Payment Successful! Receipt: $receipt</div>";
    }
    if (isset($_GET['del_payment'])) {
        $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->execute([$_GET['del_payment']]);
        $msg = "<div class='alert alert-danger'>Payment record has been removed.</div>";
    }
}

// TEACHER ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'teacher') {
    if (isset($_POST['update_grades'])) {
        foreach($_POST['grades'] as $enrollment_id => $data) {
            $stmt = $pdo->prepare("UPDATE enrollments SET prelim=?, midterm=?, final=?, remarks=? WHERE id=?");
            $stmt->execute([$data['p'], $data['m'], $data['f'], $data['r'], $enrollment_id]);
        }
        $msg = "<div class='alert alert-success'>Grades updated successfully.</div>";
    }
}

// DEAN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'dean') {
    if (isset($_POST['save_subject'])) {
        if (!empty($_POST['subject_id'])) {
            $stmt = $pdo->prepare("UPDATE subjects SET subject_code=?, subject_title=?, units=?, sy=?, sem=?, course=?, teacher_id=?, schedule=? WHERE id=?");
            $stmt->execute([$_POST['code'], $_POST['title'], $_POST['units'], $_POST['sy'], $_POST['sem'], $_POST['course'], $_POST['teacher_id'], $_POST['schedule'], $_POST['subject_id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_title, units, sy, sem, course, teacher_id, schedule) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$_POST['code'], $_POST['title'], $_POST['units'], $_POST['sy'], $_POST['sem'], $_POST['course'], $_POST['teacher_id'], $_POST['schedule']]);
        }
        $msg = "<div class='alert alert-success'>Subject data updated.</div>";
    }
    if (isset($_GET['del_sub'])) {
        $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([$_GET['del_sub']]);
        $msg = "<div class='alert alert-danger'>Subject deleted.</div>";
    }
}

// FINANCE ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'finance') {
    if (isset($_POST['add_fee'])) {
        $student_id = ($_POST['target_student'] == "0") ? null : $_POST['target_student'];
        $stmt = $pdo->prepare("INSERT INTO fee_schedules (fee_name, fee_type, amount, sy, sem, student_id) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$_POST['fee_name'], $_POST['fee_type'], $_POST['amount'], $_POST['sy'], $_POST['sem'], $student_id]);
        $msg = "<div class='alert alert-success'>Fee schedule updated.</div>";
    }
    if (isset($_GET['del_fee'])) {
        $pdo->prepare("DELETE FROM fee_schedules WHERE id=?")->execute([$_GET['del_fee']]);
        $msg = "<div class='alert alert-danger'>Fee removed.</div>";
    }
}

// ADMIN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_GET['approve_id'])) {
        $pdo->prepare("UPDATE users SET status='approved' WHERE id=?")->execute([$_GET['approve_id']]);
        $msg = "<div class='alert alert-success'>User Approved.</div>";
    }
    if (isset($_GET['reject_id'])) {
        $pdo->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$_GET['reject_id']]);
        $msg = "<div class='alert alert-danger'>User Rejected.</div>";
    }
    if (isset($_POST['create_staff'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status) VALUES (?, ?, ?, ?, ?, 'approved')")
            ->execute([$_POST['fname'], $_POST['lname'], $_POST['user'], $hash, $_POST['role']]);
        $msg = "<div class='alert alert-success'>Staff account created successfully.</div>";
    }
}

// --- STUDENT DASH ACTIONS ---
if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'student') {
    
    // Handle Profile Update (The code we just added)
    if (isset($_POST['student_update_profile'])) {
        $photo_query = "";
        if(!empty($_FILES['photo']['name'])) {
            $photo_name = time() . "_" . $_FILES['photo']['name'];
            if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            move_uploaded_file($_FILES['photo']['tmp_name'], "uploads/" . $photo_name);
            $photo_query = ", photo='$photo_name'";
        }
        $stmt = $pdo->prepare("UPDATE users SET firstname=?, lastname=?, email=?, birthdate=?, address=? $photo_query WHERE id=?");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['email'], $_POST['bdate'], $_POST['addr'], $_SESSION['user_id']]);
        $msg = "<div class='alert alert-success'>Your profile has been updated!</div>";
        
        // Refresh session data
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $uStmt->execute([$_SESSION['user_id']]);
        $currentUser = $uStmt->fetch();
    }

    // Handle Enrollment
    if (isset($_GET['enroll_id'])) {
        $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
        $check->execute([$_SESSION['user_id'], $_GET['enroll_id']]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?, ?)")
                ->execute([$_SESSION['user_id'], $_GET['enroll_id']]);
            $msg = "<div class='alert alert-success'>Subject added to your load.</div>";
        }
    }

    //  Handle Dropping
    if (isset($_GET['drop_id'])) {
        $pdo->prepare("DELETE FROM enrollments WHERE id = ? AND student_id = ?")
            ->execute([$_GET['drop_id'], $_SESSION['user_id']]);
        $msg = "<div class='alert alert-warning'>Subject dropped.</div>";
    }
} 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Campus Core Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f4f7f6; }
        .sidebar { min-height: 100vh; background: #2c3e50; color: white; }
        .sidebar a { color: #bdc3c7; text-decoration: none; padding: 12px 20px; display: block; border-bottom: 1px solid #34495e; }
        .sidebar a:hover, .sidebar a.active { background: #3498db; color: white; }
        .profile-img-nav { width: 80px; height: 80px; object-fit: cover; border: 3px solid #3498db; }
        @media print { .no-print { display: none !important; } .sidebar { display: none !important; } .col-md-10 { width: 100% !important; } }
    </style>
</head>
<body>
<?php if (!isset($_SESSION['user_id'])): ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="text-center mb-4"><h3>Campus Core Management</h3></div>
                <?= $msg ?>
                <div class="card p-4 shadow-sm border-0">
                    <?php if ($view == 'login'): ?>
                        <form method="POST">
                            <label>Username</label><input type="text" name="username" class="form-control mb-3" required autocomplete="off">
                            <label>Password</label><input type="password" name="password" class="form-control mb-3" required>
                            <button name="login" class="btn btn-primary w-100">Sign In</button>
                        </form>
                        <div class="text-center mt-3"><a href="?view=register">Apply for a Student Account</a></div>
                    <?php else: ?>
                        <h5>Create Student Account</h5>
                        <form method="POST" autocomplete="off">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><input name="fname" placeholder="First Name" class="form-control" required></div>
                                <div class="col-md-6"><input name="lname" placeholder="Last Name" class="form-control" required></div>
                            </div>
                            <input name="email" type="email" placeholder="Email Address" class="form-control mb-2" required>

                            <select name="course" class="form-select mb-2" required>
                                <option value="" disabled selected>Select Course</option>
                                <optgroup label="Business, Management, and Accountancy">
                                    <option value="BS Accountancy">Bachelor of Science in Accountancy (BSA)</option>
                                    <option value="BS Business Administration">BS Business Administration</option>
                                    <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                                    <option value="BS Legal Management">BS Legal Management</option>
                                    <option value="BS Tourism/Hospitality Management">BS Tourism/Hospitality Management</option>
                                </optgroup>
                                <optgroup label="STEM & Technology">
                                    <option value="BS Computer Science">BS Computer Science</option>
                                    <option value="BS Information Technology">BS Information Technology</option>
                                    <option value="BS Engineering">BS Civil/Mechanical/Electrical Engineering</option>
                                    <option value="BS Architecture">BS Architecture</option>
                                    <option value="BS Nursing">BS Nursing</option>
                                    <option value="BS Psychology">BS Psychology</option>
                                </optgroup>
                                <optgroup label="Arts & Social Sciences">
                                    <option value="AB Communication/Journalism">AB Communication/Journalism</option>
                                    <option value="AB Political Science">AB Political Science</option>
                                    <option value="BA Fine Arts/Multimedia Arts">BA Fine Arts/Multimedia Arts</option>
                                </optgroup>
                                <optgroup label="Education">
                                    <option value="Bachelor in Elementary Education">Bachelor in Elementary Education (BEED)</option>
                                    <option value="Bachelor in Secondary Education">Bachelor in Secondary Education (BSEd)</option>
                                </optgroup>
                                <optgroup label="Specializations (2026)">
                                    <option value="Data Science: Applied Math">Data Science: Applied Mathematics</option>
                                    <option value="Robotics & Mechatronics">Engineering: Robotics and Mechatronics</option>
                                </optgroup>
                            </select>

                            <input name="user" placeholder="Desired Username" class="form-control mb-2" required autocomplete="off">
                            <input name="password" type="password" placeholder="Password" class="form-control mb-3" required autocomplete="new-password">
                            <button name="register_user" class="btn btn-success w-100">Submit Application</button>
                        </form>
                        <div class="text-center mt-3"><a href="?view=login">Back to Login</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-0 no-print">
                <div class="p-4 bg-dark text-center">
                    <?php if($_SESSION['role'] == 'student'): ?>
                        <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle profile-img-nav mb-2 shadow">
                    <?php endif; ?>
                    <h6><?= $_SESSION['name'] ?></h6>
                    <small class="text-info"><?= strtoupper($_SESSION['role']) ?></small>
                </div>
                <a href="?"><i class="bi bi-house"></i> Home</a>
                
                <?php if($_SESSION['role'] == 'admin'): ?>
                    <a href="?page=approvals">User Approvals</a>
                    <a href="?page=create_staff">Create Staff</a>
                <?php elseif($_SESSION['role'] == 'records'): ?>
					<a href="?page=rec_students"><i class="bi bi-people"></i> Manage Students</a>
					<a href="?page=rec_tor"><i class="bi bi-file-earmark-text"></i> TOR Dashboard</a>
                <?php elseif($_SESSION['role'] == 'teacher'): ?>
                    <a href="?page=teacher_classes"><i class="bi bi-journal-bookmark"></i> My Classes</a>
                    <a href="?page=teacher_grades"><i class="bi bi-pencil-square"></i> Encoding of Grades</a>
                <?php elseif($_SESSION['role'] == 'dean'): ?>
                    <a href="?page=dean_courses"><i class="bi bi-mortarboard"></i> Courses & Subjects</a>
                    <a href="?page=dean_registered_students"><i class="bi bi-person-lines-fill"></i> Registered Students</a>
                    <a href="?page=dean_enrollment"><i class="bi bi-people"></i> Enrolled Students</a>
                    <a href="?page=dean_teachers"><i class="bi bi-person-badge"></i> Teachers & Schedules</a>
                <?php elseif($_SESSION['role'] == 'finance'): ?>
                    <a href="?page=finance_load"><i class="bi bi-journal-text"></i> Student Loads</a>
                    <a href="?page=finance_fees"><i class="bi bi-cash-coin"></i> Fee Schedules</a>
                    <a href="?page=finance_billing"><i class="bi bi-receipt-cutoff"></i> Student Billing/Balance</a>
                <?php elseif($_SESSION['role'] == 'cashier'): ?>
                    <a href="?page=cashier_billing"><i class="bi bi-wallet2"></i> Student Payables</a>
                    <a href="?page=cashier_payments"><i class="bi bi-cash"></i> Receive Payments</a>
                    <a href="?page=cashier_reports"><i class="bi bi-graph-up"></i> Collection Reports</a>
                <?php elseif($_SESSION['role'] == 'student'): ?>
                    <a href="?page=my_subjects"><i class="bi bi-book"></i> My Subjects / Enroll</a>
                    <a href="?page=my_grades"><i class="bi bi-award"></i> My Grades</a>
                    <a href="?page=my_billing"><i class="bi bi-wallet2"></i> Accounts & Balance</a>
                    <a href="?page=my_permit"><i class="bi bi-ticket-perforated"></i> Exam Permit</a>
                <?php endif; ?>
                
                <a href="?action=logout" class="text-danger mt-5"><i class="bi bi-power"></i> Logout</a>
            </div>
            <div class="col-md-10 p-4">
                <?= $msg ?>
                <?php
                $page = $_GET['page'] ?? 'home';
                
               // --- HOME DASHBOARD (Personal Data for Student) ---
                if ($page == 'home') {
                    if ($_SESSION['role'] == 'student') {
                        ?>
                        <h3>Student Dashboard</h3>
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card border-0 shadow-sm text-center p-4">
                                    <div class="mb-3">
                                        <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle shadow-sm border" style="width:150px; height:150px; object-fit:cover;">
                                    </div>
                                    <h4 class="mb-0"><?= $currentUser['firstname'] ?> <?= $currentUser['lastname'] ?></h4>
                                    <p class="text-muted small">Student ID: STU-00<?= $currentUser['id'] ?></p>
                                    
                                    <button class="btn btn-sm btn-outline-primary mt-2" data-bs-toggle="modal" data-bs-target="#editMyProfile">
                                        <i class="bi bi-pencil"></i> Edit Profile
                                    </button>
                                    <hr>
                                    <div class="text-start">
                                        <p class="mb-1"><strong>Email:</strong> <?= $currentUser['email'] ?></p>
                                        <p class="mb-1"><strong>Course:</strong> <?= $currentUser['course'] ?></p>
                                        <p class="mb-0"><strong>Address:</strong> <?= $currentUser['address'] ?: 'Not set' ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade" id="editMyProfile" tabindex="-1">
                                <div class="modal-dialog">
                                    <form method="POST" enctype="multipart/form-data" class="modal-content">
                                        <div class="modal-header"><h5>Update My Information</h5></div>
                                        <div class="modal-body text-start">
                                            <div class="row mb-2">
                                                <div class="col-6"><label>First Name</label><input name="fname" value="<?= $currentUser['firstname'] ?>" class="form-control" required></div>
                                                <div class="col-6"><label>Last Name</label><input name="lname" value="<?= $currentUser['lastname'] ?>" class="form-control" required></div>
                                            </div>
                                            <label>Email</label><input type="email" name="email" value="<?= $currentUser['email'] ?>" class="form-control mb-2" required>
                                            <label>Birthdate</label><input type="date" name="bdate" value="<?= $currentUser['birthdate'] ?>" class="form-control mb-2">
                                            <label>Address</label><textarea name="addr" class="form-control mb-2"><?= $currentUser['address'] ?></textarea>
                                            <label>Change Photo</label><input type="file" name="photo" class="form-control" accept="image/*">
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button name="student_update_profile" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="col-md-8">
                                <div class="card border-0 shadow-sm p-4">
                                    <h5>Welcome back, <?= $currentUser['firstname'] ?>!</h5>
                                    <p>You can manage your subjects and view your grades using the menu on the left.</p>
                                </div>
                            </div>
                        </div>
                        <?php
                    } else {
                        echo "<h3>Dashboard</h3><p>Welcome back, " . $_SESSION['name'] . ".</p>";
                    }
                }
                // --- RECORDS PAGES ---
                elseif ($page == 'rec_students' && $_SESSION['role'] == 'records') {
                    echo "<h3>Student Information Management</h3>";
                    $students = $pdo->query("SELECT * FROM users WHERE role='student'")->fetchAll();
                    ?>
                    <table class="table table-hover bg-white mt-3 shadow-sm">
                        <thead class="table-dark"><tr><th>Photo</th><th>Name</th><th>Birthdate</th><th>Address</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach($students as $s): ?>
                        <tr>
                            <td><img src="uploads/<?= $s['photo'] ?>" width="40" height="40" class="rounded-circle border"></td>
                            <td><?= $s['lastname'] ?>, <?= $s['firstname'] ?></td>
                            <td><?= $s['birthdate'] ?></td>
                            <td><?= $s['address'] ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editS<?= $s['id'] ?>">Edit Info</button>
                                <div class="modal fade" id="editS<?= $s['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog"><form method="POST" enctype="multipart/form-data" class="modal-content">
                                        <div class="modal-header"><h5>Edit Student</h5></div>
                                        <div class="modal-body text-start">
                                            <input type="hidden" name="sid" value="<?= $s['id'] ?>">
                                            <label>First Name</label><input name="fname" value="<?= $s['firstname'] ?>" class="form-control mb-2">
                                            <label>Last Name</label><input name="lname" value="<?= $s['lastname'] ?>" class="form-control mb-2">
                                            <label>Birthdate</label><input type="date" name="bdate" value="<?= $s['birthdate'] ?>" class="form-control mb-2">
                                            <label>Address</label><textarea name="addr" class="form-control mb-2"><?= $s['address'] ?></textarea>
                                            <label>Picture</label><input type="file" name="photo" class="form-control">
                                        </div>
                                        <div class="modal-footer"><button name="update_student_profile" class="btn btn-success">Save</button></div>
                                    </form></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                }
                elseif ($page == 'rec_tor' && $_SESSION['role'] == 'records') {
                    echo "<h3>Transcript of Records (TOR)</h3>";
                    ?>
                    <div class="card p-3 mb-4 no-print shadow-sm">
                        <form method="GET" class="row g-2">
                            <input type="hidden" name="page" value="rec_tor">
                            <div class="col-md-9">
                                <select name="student_id" class="form-select" required>
                                    <option value="">-- Select Student --</option>
                                    <?php 
                                    $st = $pdo->query("SELECT id, lastname, firstname FROM users WHERE role='student'")->fetchAll();
                                    foreach($st as $s) echo "<option value='{$s['id']}'>{$s['lastname']}, {$s['firstname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3"><button class="btn btn-dark w-100">Load TOR</button></div>
                        </form>
                    </div>
                    <?php if (!empty($_GET['student_id'])): 
                        $sid = $_GET['student_id'];
                        $stud = $pdo->prepare("SELECT * FROM users WHERE id=?"); $stud->execute([$sid]); $si = $stud->fetch();
                        $grades = $pdo->prepare("SELECT e.*, s.subject_code, s.subject_title, s.units, s.sy, s.sem FROM enrollments e JOIN subjects s ON e.subject_id = s.id WHERE e.student_id = ? ORDER BY s.sy ASC, s.sem ASC");
                        $grades->execute([$sid]);
                        $all_grades = $grades->fetchAll();
                    ?>
                        <div class="p-5 bg-white border">
                            <div class="text-center mb-4">
                                <h2>TRANSCRIPT OF RECORDS</h2>
                                <hr>
                                <div class="row text-start mt-4">
                                    <div class="col-6"><strong>Name:</strong> <?= $si['lastname'] ?>, <?= $si['firstname'] ?></div>
                                    <div class="col-6"><strong>Address:</strong> <?= $si['address'] ?></div>
                                </div>
                            </div>
                            <table class="table table-bordered">
                                <thead class="table-light"><tr><th>SY/Sem</th><th>Code</th><th>Subject</th><th>Units</th><th>P</th><th>M</th><th>F</th><th>Remarks</th><th class="no-print">Edit</th></tr></thead>
                                <tbody>
                                <?php foreach($all_grades as $g): ?>
                                <tr>
                                    <td><?= $g['sy'] ?> - <?= $g['sem'] ?></td>
                                    <td><?= $g['subject_code'] ?></td>
                                    <td><?= $g['subject_title'] ?></td>
                                    <td><?= $g['units'] ?></td>
                                    <td><?= $g['prelim'] ?></td><td><?= $g['midterm'] ?></td><td><?= $g['final'] ?></td>
                                    <td class="fw-bold"><?= $g['remarks'] ?></td>
                                    <td class="no-print"><button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modG<?= $g['id'] ?>"><i class="bi bi-pencil"></i></button>
                                        <div class="modal fade" id="modG<?= $g['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog"><form method="POST" class="modal-content text-start">
                                                <div class="modal-header"><h6>Edit Grade</h6></div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="eid" value="<?= $g['id'] ?>">
                                                    <div class="row g-2 mb-2">
                                                        <div class="col-4"><label>P</label><input name="p" value="<?= $g['prelim'] ?>" class="form-control"></div>
                                                        <div class="col-4"><label>M</label><input name="m" value="<?= $g['midterm'] ?>" class="form-control"></div>
                                                        <div class="col-4"><label>F</label><input name="f" value="<?= $g['final'] ?>" class="form-control"></div>
                                                    </div>
                                                    <label>Remarks</label><input name="r" value="<?= $g['remarks'] ?>" class="form-control">
                                                </div>
                                                <div class="modal-footer"><button name="records_update_grade" class="btn btn-primary btn-sm">Update</button></div>
                                            </form></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end mt-3 no-print"><button onclick="window.print()" class="btn btn-dark">Print PDF View</button></div>
                        </div>
                    <?php endif;
                }
                // --- CASHIER PAGES ---
                elseif ($page == 'cashier_billing' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Student Payables & Balance Summary</h3>";
                    ?>
                    <table class="table table-hover bg-white shadow-sm mt-3">
                        <thead class="table-dark">
                            <tr>
                                <th>Student Name</th>
                                <th>Total Assessment</th>
                                <th>Amount Paid</th>
                                <th>Balance Due</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student' ORDER BY lastname ASC")->fetchAll();
                            foreach($students as $b): 
                                // Calculate Load (Global + Specific)
                                $load_stmt = $pdo->prepare("SELECT SUM(amount) FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                                $load_stmt->execute([$b['id']]);
                                $total_assessment = $load_stmt->fetchColumn() ?: 0;
                                // Calculate Payments
                                $pay_stmt = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payments WHERE student_id = ?");
                                $pay_stmt->execute([$b['id']]);
                                $total_paid = $pay_stmt->fetchColumn();
                                $balance = $total_assessment - $total_paid;
                                $status_color = ($balance <= 0) ? 'success' : 'danger';
                                $status_text = ($balance <= 0) ? 'Fully Paid' : 'With Balance';
                            ?>
                            <tr>
                                <td><?= $b['lastname'] ?>, <?= $b['firstname'] ?></td>
                                <td>₱<?= number_format($total_assessment, 2) ?></td>
                                <td class="text-success">₱<?= number_format($total_paid, 2) ?></td>
                                <td class="fw-bold text-danger">₱<?= number_format($balance, 2) ?></td>
                                <td><span class="badge bg-<?= $status_color ?>"><?= $status_text ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                }
                elseif ($page == 'cashier_payments' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Receive Payments</h3>";
                    ?>
                    <div class="card p-4 border-0 shadow-sm mb-4">
                        <form method="POST" class="row g-3">
                            <div class="col-md-4">
                                <label>Select Student</label>
                                <select name="sid" class="form-select" required>
                                    <?php 
                                    $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student'")->fetchAll();
                                    foreach($students as $s) echo "<option value='{$s['id']}'>{$s['lastname']}, {$s['firstname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2"><label>SY</label><input name="sy" placeholder="2024-2025" class="form-control" required></div>
                            <div class="col-md-2"><label>Semester</label><select name="sem" class="form-select"><option>1st</option><option>2nd</option></select></div>
                            <div class="col-md-2"><label>Amount</label><input name="amt" type="number" step="0.01" class="form-control" required></div>
                            <div class="col-md-2"><label>&nbsp;</label><button name="process_payment" class="btn btn-success w-100">Post Payment</button></div>
                        </form>
                    </div>
                    <h5>Recent Payments</h5>
                    <table class="table bg-white shadow-sm">
                        <thead class="table-dark">
                            <tr><th>Receipt #</th><th>Student</th><th>Amount</th><th>Date</th><th class="no-print">Actions</th></tr>
                        </thead>
                        <?php
                        $recent = $pdo->query("SELECT p.*, u.firstname, u.lastname FROM payments p JOIN users u ON p.student_id = u.id ORDER BY p.pay_date DESC LIMIT 15")->fetchAll();
                        foreach($recent as $r): ?>
                            <tr>
                                <td><?= $r['receipt_no'] ?></td>
                                <td><?= $r['lastname'] ?>, <?= $r['firstname'] ?></td>
                                <td>₱<?= number_format($r['amount'], 2) ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($r['pay_date'])) ?></td>
                                <td class="no-print">
                                    <button onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i></button>
                                    <a href="?page=cashier_payments&del_payment=<?= $r['id'] ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Are you sure you want to remove this payment record? This will update the student balance.')">
                                       <i class="bi bi-trash"></i> Remove
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php
                }
                elseif ($page == 'cashier_reports' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Cash Collection Reports</h3>";
                    ?>
                    <form method="POST" class="row g-2 mb-4 no-print">
                        <div class="col-md-3"><input type="date" name="d1" class="form-control" required></div>
                        <div class="col-md-3"><input type="date" name="d2" class="form-control" required></div>
                        <div class="col-md-2"><button name="gen_report" class="btn btn-primary w-100">Show Report</button></div>
                    </form>
                    <?php
                    if (isset($_POST['gen_report'])) {
                        $stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname FROM payments p JOIN users u ON p.student_id = u.id WHERE DATE(pay_date) BETWEEN ? AND ?");
                        $stmt->execute([$_POST['d1'], $_POST['d2']]);
                        $results = $stmt->fetchAll();
                        echo "<h5>Collection from {$_POST['d1']} to {$_POST['d2']}</h5>";
                        echo "<table class='table bg-white shadow-sm'><thead><tr><th>Date</th><th>Student</th><th>Receipt</th><th>Amount</th></tr></thead>";
                        $total = 0;
                        foreach($results as $res) {
                            echo "<tr><td>{$res['pay_date']}</td><td>{$res['lastname']}</td><td>{$res['receipt_no']}</td><td>₱".number_format($res['amount'],2)."</td></tr>";
                            $total += $res['amount'];
                        }
                        echo "<tr><th colspan='3' class='text-end'>TOTAL COLLECTION:</th><th>₱".number_format($total,2)."</th></tr></table>";
                        echo "<button onclick='window.print()' class='btn btn-dark no-print'>Print Report</button>";
                    }
                }
                // --- ADMIN PAGES ---
                elseif ($page == 'approvals' && $_SESSION['role'] == 'admin') {
                    echo "<h3>Pending Approvals</h3>";
                    $pending = $pdo->query("SELECT * FROM users WHERE status='pending'")->fetchAll();
                    echo "<table class='table bg-white'><tr><th>Name</th><th>Role</th><th>Action</th></tr>";
                    foreach($pending as $p) {
                        echo "<tr><td>{$p['firstname']} {$p['lastname']}</td><td>{$p['role']}</td><td>
                            <a href='?page=approvals&approve_id={$p['id']}' class='btn btn-sm btn-success'>Approve</a>
                            <a href='?page=approvals&reject_id={$p['id']}' class='btn btn-sm btn-danger'>Reject</a>
                        </td></tr>";
                    }
                    echo "</table>";
                }
                elseif ($page == 'create_staff' && $_SESSION['role'] == 'admin') {
                    echo "<h3>Create Staff Account</h3>";
                    ?>
                    <div class="card p-4 border-0 shadow-sm" style="max-width: 500px;">
                        <form method="POST">
                            <input name="fname" placeholder="First Name" class="form-control mb-2" required>
                            <input name="lname" placeholder="Last Name" class="form-control mb-2" required>
                            <input name="user" placeholder="Username" class="form-control mb-2" required>
                            <input name="password" type="password" placeholder="Password" class="form-control mb-2" required>
                            <select name="role" class="form-select mb-3">
                                <option value="teacher">Teacher</option>
                                <option value="dean">Dean</option>
                                <option value="records">Records</option>
                                <option value="cashier">Cashier</option>
                                <option value="finance">Finance</option>
                            </select>
                            <button name="create_staff" class="btn btn-primary w-100">Register Staff</button>
                        </form>
                    </div>
                    <?php
                }
                // --- TEACHER PAGES ---
                elseif ($page == 'teacher_classes' && $_SESSION['role'] == 'teacher') {
                    echo "<h3>My Assigned Classes</h3>";
                    $classes = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ?");
                    $classes->execute([$_SESSION['user_id']]);
                    echo "<table class='table bg-white shadow-sm'><thead><tr><th>Code</th><th>Title</th><th>Course</th><th>Schedule</th></tr></thead>";
                    foreach($classes->fetchAll() as $c) echo "<tr><td>{$c['subject_code']}</td><td>{$c['subject_title']}</td><td>{$c['course']}</td><td>{$c['schedule']}</td></tr>";
                    echo "</table>";
                }
                elseif ($page == 'teacher_grades' && $_SESSION['role'] == 'teacher') {
                    echo "<h3>Grade Encoding</h3>";
                    
                    // Fetch students grouped and ordered by Course
                    $q = $pdo->prepare("SELECT e.*, u.firstname, u.lastname, s.subject_title, s.course 
                                      FROM enrollments e 
                                      JOIN users u ON e.student_id = u.id 
                                      JOIN subjects s ON e.subject_id = s.id 
                                      WHERE s.teacher_id = ? 
                                      ORDER BY s.course ASC, u.lastname ASC");
                    $q->execute([$_SESSION['user_id']]);
                    $list = $q->fetchAll(PDO::FETCH_ASSOC); 
                    
                    if (empty($list)) { 
                        echo "<div class='alert alert-info mt-3'>No students enrolled in your subjects.</div>"; 
                    } else {
                        ?>
                        <form method="POST">
                            <?php 
                            $current_course = null; 
                            foreach($list as $s): 
                                // Detect when the course changes to start a new section
                                if ($current_course !== $s['course']): 
                                    if ($current_course !== null) echo "</tbody></table>"; // Close previous table
                                    $current_course = $s['course'];
                            ?>
                                <div class="mt-4 mb-2 p-2 bg-secondary text-white rounded shadow-sm">
                                    <i class="bi bi-mortarboard-fill"></i> 
                                    <strong>COURSE: <?= strtoupper($current_course ?: 'GENERAL / UNSET') ?></strong>
                                </div>
                                <table class="table bg-white shadow-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Subject</th>
                                            <th style="width: 100px;">P</th>
                                            <th style="width: 100px;">M</th>
                                            <th style="width: 100px;">F</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                <?php endif; ?>
                                <tr>
                                    <td><strong><?= $s['lastname'].", ".$s['firstname'] ?></strong></td>
                                    <td><small><?= $s['subject_title'] ?></small></td>
                                    <td><input type='number' step='0.1' name='grades[<?= $s['id'] ?>][p]' value='<?= $s['prelim'] ?>' class='form-control form-control-sm'></td>
                                    <td><input type='number' step='0.1' name='grades[<?= $s['id'] ?>][m]' value='<?= $s['midterm'] ?>' class='form-control form-control-sm'></td>
                                    <td><input type='number' step='0.1' name='grades[<?= $s['id'] ?>][f]' value='<?= $s['final'] ?>' class='form-control form-control-sm'></td>
                                    <td><input type='text' name='grades[<?= $s['id'] ?>][r]' value='<?= $s['remarks'] ?>' class='form-control form-control-sm'></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="mt-3">
                            <button name="update_grades" class="btn btn-success shadow">
                                <i class="bi bi-check-circle"></i> Save All Grades
                            </button>
                        </div>
                    </form>
                    <?php
                    }
                }
                // --- FINANCE PAGES ---
                elseif ($page == 'finance_load' && $_SESSION['role'] == 'finance') {
                    echo "<h3>Student Loads</h3>";
                    $stmt = $pdo->query("SELECT e.id as eid, u.firstname, u.lastname, s.subject_code, s.subject_title, s.units, s.sy, s.sem 
                                         FROM enrollments e 
                                         JOIN users u ON e.student_id = u.id 
                                         JOIN subjects s ON e.subject_id = s.id 
                                         ORDER BY u.lastname ASC, s.sy DESC");
                    $loads = $stmt->fetchAll();
                    if (!$loads) {
                        echo "<div class='alert alert-info mt-3'>No active student loads found. Ensure students have added subjects.</div>";
                    } else {
                        ?>
                        <table class="table bg-white shadow-sm mt-3">
                            <thead class="table-dark">
                                <tr>
                                    <th>Student Name</th>
                                    <th>Code</th>
                                    <th>Subject Title</th>
                                    <th>Units</th>
                                    <th>Term (SY/Sem)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($loads as $l): ?>
                                <tr>
                                    <td><?= $l['lastname'] ?>, <?= $l['firstname'] ?></td>
                                    <td><?= $l['subject_code'] ?></td>
                                    <td><?= $l['subject_title'] ?></td>
                                    <td><?= $l['units'] ?></td>
                                    <td><?= $l['sy'] ?> - <?= $l['sem'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                    }
                }
                elseif ($page == 'finance_fees' && $_SESSION['role'] == 'finance') {
                    ?>
                    <h3>Manage Fee Schedules</h3>
                    <div class="card p-4 mb-4 border-0 shadow-sm">
                        <form method="POST" class="row g-2">
                            <div class="col-md-3">
                                <label>Target Student</label>
                                <select name="target_student" class="form-select">
                                    <option value="0">-- All Students --</option>
                                    <?php 
                                    $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student'")->fetchAll();
                                    foreach($students as $s) echo "<option value='{$s['id']}'>{$s['lastname']}, {$s['firstname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2"><label>Fee Name</label><input name="fee_name" class="form-control" required></div>
                            <div class="col-md-2"><label>Type</label><select name="fee_type" class="form-select"><option>Tuition</option><option>Misc</option><option>Lab</option><option>Other</option></select></div>
                            <div class="col-md-2"><label>Amount</label><input name="amount" type="number" step="0.01" class="form-control" required></div>
                            <div class="col-md-2"><label>SY/Sem</label>
                                <div class="input-group">
                                    <input name="sy" placeholder="SY" class="form-control" required>
                                    <select name="sem" class="form-select"><option>1st</option><option>2nd</option></select>
                                </div>
                            </div>
                            <div class="col-md-1"><label>&nbsp;</label><button name="add_fee" class="btn btn-primary w-100"><i class="bi bi-plus"></i></button></div>
                        </form>
                    </div>
                    
                    <table class="table bg-white shadow-sm">
                        <thead class="table-dark"><tr><th>Target</th><th>Fee Name</th><th>Type</th><th>Amount</th><th>SY/Sem</th><th>Action</th></tr></thead>
                        <?php
                        $fees = $pdo->query("SELECT f.*, u.lastname as student_name FROM fee_schedules f LEFT JOIN users u ON f.student_id = u.id ORDER BY f.sy DESC, f.student_id ASC")->fetchAll();
                        foreach($fees as $f) {
                            $target = $f['student_name'] ? "<span class='badge bg-info text-dark'>{$f['student_name']}</span>" : "<span class='badge bg-secondary'>Global</span>";
                            echo "<tr><td>$target</td><td>{$f['fee_name']}</td><td>{$f['fee_type']}</td><td>₱".number_format($f['amount'],2)."</td><td>{$f['sy']} - {$f['sem']}</td><td><a href='?page=finance_fees&del_fee={$f['id']}' class='btn btn-sm btn-danger'>Del</a></td></tr>";
                        }
                        ?>
                    </table>
                    <?php
                }
                elseif ($page == 'finance_billing' && $_SESSION['role'] == 'finance') {
                    echo "<h3>Student Payable Fees & Balance</h3>";
                    ?>
                    <table class='table bg-white shadow-sm'><thead><tr><th>Student</th><th>Total Assessment</th><th>Paid</th><th>Balance</th></tr></thead>
                    <?php
                    $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student'")->fetchAll();
                    foreach($students as $b) {
                        $load_stmt = $pdo->prepare("SELECT SUM(amount) FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                        $load_stmt->execute([$b['id']]);
                        $assessment = $load_stmt->fetchColumn() ?: 0;
                        $pay_stmt = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payments WHERE student_id = ?");
                        $pay_stmt->execute([$b['id']]);
                        $paid = $pay_stmt->fetchColumn();
                        $balance = $assessment - $paid;
                        echo "<tr><td>{$b['lastname']}, {$b['firstname']}</td><td>₱".number_format($assessment,2) . "</td><td>₱".number_format($paid,2)."</td><td class='fw-bold text-danger'>₱".number_format($balance,2)."</td></tr>";
                    }
                    ?>
                    </table>
                    <?php
                }
                // --- DEAN PAGES ---
                elseif ($page == 'dean_courses' && $_SESSION['role'] == 'dean') {
                    $edit_sub = null;
                    if(isset($_GET['edit_id'])){ $s = $pdo->prepare("SELECT * FROM subjects WHERE id=?"); $s->execute([$_GET['edit_id']]); $edit_sub = $s->fetch(); }
                    ?>
                    <h3>Course & Subject Management</h3>
                    <div class="card p-4 mb-4 border-0 shadow-sm">
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="subject_id" value="<?= $edit_sub['id'] ?? '' ?>">
                            <div class="col-md-2"><input name="sy" placeholder="SY" class="form-control" value="<?= $edit_sub['sy'] ?? '' ?>" required></div>
                            <div class="col-md-2"><select name="sem" class="form-select"><option <?= ($edit_sub['sem']??'')=='1st'?'selected':'' ?>>1st</option><option <?= ($edit_sub['sem']??'')=='2nd'?'selected':'' ?>>2nd</option></select></div>
                            <div class="col-md-2"><input name="course" placeholder="Course" class="form-control" value="<?= $edit_sub['course'] ?? '' ?>" required></div>
                            <div class="col-md-2"><input name="code" placeholder="Code" class="form-control" value="<?= $edit_sub['subject_code'] ?? '' ?>" required></div>
                            <div class="col-md-3"><input name="title" placeholder="Title" class="form-control" value="<?= $edit_sub['subject_title'] ?? '' ?>" required></div>
                            <div class="col-md-1"><input name="units" type="number" placeholder="Units" class="form-control" value="<?= $edit_sub['units'] ?? '' ?>" required></div>
                            <div class="col-md-3">
                                <select name="teacher_id" class="form-select">
                                    <option value="0">Unassigned</option>
                                    <?php 
                                    $techs = $pdo->query("SELECT id, lastname FROM users WHERE role='teacher'")->fetchAll();
                                    foreach($techs as $t) echo "<option value='{$t['id']}' ".($edit_sub['teacher_id']??0 == $t['id']?'selected':'').">{$t['lastname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-7"><input name="schedule" placeholder="Schedule" class="form-control" value="<?= $edit_sub['schedule'] ?? '' ?>"></div>
                            <div class="col-md-2"><button name="save_subject" class="btn btn-primary w-100"><?= $edit_sub ? 'Update' : 'Add' ?></button></div>
                        </form>
                    </div>
                    <table class="table bg-white shadow-sm">
                        <thead class="table-dark"><tr><th>SY/Sem</th><th>Course</th><th>Subject</th><th>Units</th><th>Action</th></tr></thead>
                        <?php
                        $subs = $pdo->query("SELECT s.*, u.lastname FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY s.sy DESC, s.course ASC")->fetchAll();
                        foreach($subs as $s) echo "<tr><td>{$s['sy']} - {$s['sem']}</td><td>{$s['course']}</td><td>{$s['subject_code']} - {$s['subject_title']}</td><td>{$s['units']}</td><td><a href='?page=dean_courses&edit_id={$s['id']}' class='btn btn-sm btn-info text-white'>Edit</a></td></tr>";
                        ?>
                    </table>
                    <?php
                }
                elseif ($page == 'dean_registered_students' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Registered Students</h3>";
                    $students = $pdo->query("SELECT firstname, lastname, email, username, status, id FROM users WHERE role = 'student' ORDER BY lastname ASC")->fetchAll();
                    echo "<table class='table table-hover bg-white shadow-sm'><thead class='table-dark'><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th></tr></thead>";
                    foreach($students as $s) echo "<tr><td>{$s['lastname']}, {$s['firstname']}'</td><td>{$s['username']}</td><td>{$s['email']}</td><td>".strtoupper($s['status'])."</td></tr>";
                    echo "</table>";
                }
                elseif ($page == 'dean_enrollment' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Enrolled Students List</h3>";
                    $enrolled = $pdo->query("SELECT DISTINCT u.firstname, u.lastname, u.email, u.id FROM users u JOIN enrollments e ON u.id = e.student_id WHERE u.role = 'student'")->fetchAll();
                    ?>
                    <table class="table bg-white shadow-sm mt-3">
                        <thead class="table-dark"><tr><th>Student ID</th><th>Full Name</th><th>Email Address</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach($enrolled as $row): ?>
                            <tr><td>STU-00<?= $row['id'] ?></td><td><?= $row['lastname'] ?>, <?= $row['firstname'] ?></td><td><?= $row['email'] ?></td><td><span class="badge bg-success">Enrolled</span></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                }
                elseif ($page == 'dean_teachers' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Teacher Schedules & Assignments</h3>";
                    $schedules = $pdo->query("SELECT s.*, u.firstname, u.lastname FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY u.lastname ASC")->fetchAll();
                    ?>
                    <table class="table bg-white shadow-sm mt-3">
                        <thead class="table-dark"><tr><th>Instructor</th><th>Subject Code</th><th>Subject Title</th><th>Schedule</th></tr></thead>
                        <tbody>
                            <?php foreach($schedules as $sch): ?>
                            <tr><td><?= $sch['lastname'] ? $sch['lastname'].", ".$sch['firstname'] : "<span class='text-danger'>Unassigned</span>" ?></td><td><?= $sch['subject_code'] ?></td><td><?= $sch['subject_title'] ?></td><td><?= $sch['schedule'] ? $sch['schedule'] : 'TBA' ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                }
                // --- STUDENT PAGES ---
                elseif ($page == 'my_subjects' && $_SESSION['role'] == 'student') {
                    echo "<h3>Enrolled Subjects</h3>";
                    $my_subs = $pdo->prepare("SELECT e.id as eid, s.* FROM enrollments e JOIN subjects s ON e.subject_id = s.id WHERE e.student_id = ? ORDER BY s.sy DESC, s.sem DESC");
                    $my_subs->execute([$_SESSION['user_id']]);
                    echo "<table class='table bg-white shadow-sm mb-4'><thead><tr><th>SY/Sem</th><th>Code</th><th>Subject</th><th>Units</th><th>Action</th></tr></thead>";
                    while($r = $my_subs->fetch()) echo "<tr><td>{$r['sy']} - {$r['sem']}</td><td>{$r['subject_code']}</td><td>{$r['subject_title']}</td><td>{$r['units']}</td><td><a href='?page=my_subjects&drop_id={$r['eid']}' class='btn btn-sm btn-outline-danger'>Drop</a></td></tr>";
                    echo "</table>";
                    echo "<h3>Available Offerings</h3>";
                    $available = $pdo->query("SELECT * FROM subjects WHERE id NOT IN (SELECT subject_id FROM enrollments WHERE student_id = {$_SESSION['user_id']})")->fetchAll();
                    echo "<table class='table bg-white shadow-sm'>";
                    foreach($available as $a) echo "<tr><td>{$a['sy']} - {$a['sem']}</td><td>{$a['subject_title']}</td><td><a href='?page=my_subjects&enroll_id={$a['id']}' class='btn btn-sm btn-primary'>Add</a></td></tr>";
                    echo "</table>";
                }
                elseif ($page == 'my_grades' && $_SESSION['role'] == 'student') {
                    echo "<h3>My Academic Grades</h3>";
                    $grades_stmt = $pdo->prepare("
                        SELECT e.prelim, e.midterm, e.final, e.remarks, 
                               s.subject_code, s.subject_title, s.sy, s.sem, s.units 
                        FROM enrollments e 
                        JOIN subjects s ON e.subject_id = s.id 
                        WHERE e.student_id = ? 
                        ORDER BY s.sy DESC, s.sem DESC
                    ");
                    $grades_stmt->execute([$_SESSION['user_id']]);
                    $my_grades = $grades_stmt->fetchAll();
                    
                    if (!$my_grades) {
                        echo "<div class='alert alert-info mt-3'>No grade records found. You may not be enrolled in any subjects yet.</div>";
                    } else {
                        ?>
                        <div class="card p-4 border-0 shadow-sm mt-3">
                            <table class="table table-hover bg-white mb-0">
                                <thead class="table-dark"><tr><th>SY/Sem</th><th>Subject</th><th>Units</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Remarks</th></tr></thead>
                                <tbody>
                                    <?php foreach($my_grades as $g): ?>
                                    <tr>
                                        <td><?= $g['sy'] ?> - <?= $g['sem'] ?></td>
                                        <td><strong><?= $g['subject_code'] ?></strong><br><small class="text-muted"><?= $g['subject_title'] ?></small></td>
                                        <td><?= $g['units'] ?></td>
                                        <td><?= $g['prelim'] > 0 ? $g['prelim'] : '-' ?></td>
                                        <td><?= $g['midterm'] > 0 ? $g['midterm'] : '-' ?></td>
                                        <td><?= $g['final'] > 0 ? $g['final'] : '-' ?></td>
                                        <td class="fw-bold <?= strtolower($g['remarks']) == 'passed' ? 'text-success' : (strtolower($g['remarks']) == 'failed' ? 'text-danger' : 'text-warning') ?>">
                                            <?= $g['remarks'] ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                    }
                }
                elseif ($page == 'my_billing' && $_SESSION['role'] == 'student') {
                    $fees_stmt = $pdo->prepare("SELECT SUM(amount) as total FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                    $fees_stmt->execute([$_SESSION['user_id']]);
                    $fees = $fees_stmt->fetch();
                    $total_assessment = $fees['total'] ?? 0;
                    $paid_stmt = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) as paid FROM payments WHERE student_id = ?");
                    $paid_stmt->execute([$_SESSION['user_id']]);
                    $total_paid = $paid_stmt->fetch()['paid'];
                    $balance = $total_assessment - $total_paid;
                    ?>
                    <h3>Billing & Accounts</h3>
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card p-3 shadow-sm border-0">
                                <small class="text-muted">Total Assessment</small>
                                <h4>₱<?= number_format($total_assessment, 2) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card p-3 shadow-sm border-0">
                                <small class="text-muted">Remaining Balance</small>
                                <h4 class="<?= $balance <= 0 ? 'text-success' : 'text-danger' ?>">₱<?= number_format(max(0, $balance), 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                elseif ($page == 'my_permit' && $_SESSION['role'] == 'student') {
                    ?>
                    <div class="card p-5 text-center shadow-sm mx-auto border-0" style="max-width: 600px;">
                        <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle mx-auto mb-3" style="width:100px; height:100px; object-fit:cover;">
                        <h2>EXAM PERMIT</h2>
                        <p class="mb-0 fw-bold"><?= $_SESSION['name'] ?></p>
                        <p class="text-muted">STU-00<?= $_SESSION['user_id'] ?></p>
                        <h4 class="text-success mt-2">STATUS: CLEARED</h4>
                        <hr>
                        <small class="text-muted d-block mb-3">This permit is valid for the current examination period.</small>
                        <button onclick="window.print()" class="btn btn-dark mt-3 no-print">Print Permit</button>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>