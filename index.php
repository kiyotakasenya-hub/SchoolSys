<?php
// --- 1. DATABASE SETUP & AUTO-PATCHER ---
$dbUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;

if (!$dbUrl) {
    die("postgresql://schoolsys_5qrh_user:zPZ898i6bJtrXaHEXHKCLn4qee294Lja@dpg-d88g446l51nc73fetcf0-a.oregon-postgres.render.com:5432/schoolsys_5qrh");
}

try {
    $dbopts = parse_url($dbUrl);

    $host = $dbopts["host"];
    $port = $dbopts["port"];
    $user = $dbopts["user"];
    $password = $dbopts["pass"];
    $dbname = ltrim($dbopts["path"], '/');

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // CREATE TABLES
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            firstname VARCHAR(50),
            lastname VARCHAR(50),
            email VARCHAR(100) UNIQUE,
            username VARCHAR(50) UNIQUE,
            password VARCHAR(255),
            role VARCHAR(20) DEFAULT 'student',
            status VARCHAR(20) DEFAULT 'pending',
            course VARCHAR(150),
            address TEXT,
            birthdate DATE,
            photo VARCHAR(255) DEFAULT 'default.png'
        );

        CREATE TABLE IF NOT EXISTS subjects (
            id SERIAL PRIMARY KEY,
            subject_code VARCHAR(20), 
            subject_title VARCHAR(100),
            units INT, 
            teacher_id INT, 
            sy VARCHAR(20), 
            sem VARCHAR(20),
            course VARCHAR(150), 
            schedule VARCHAR(100)
        );

        CREATE TABLE IF NOT EXISTS enrollments (
            id SERIAL PRIMARY KEY,
            student_id INT, 
            subject_id INT,
            prelim FLOAT DEFAULT 0, 
            midterm FLOAT DEFAULT 0, 
            final FLOAT DEFAULT 0,
            remarks VARCHAR(50) DEFAULT 'No Grade'
        );

        CREATE TABLE IF NOT EXISTS payments (
            id SERIAL PRIMARY KEY,
            student_id INT, 
            amount DECIMAL(10,2), 
            receipt_no VARCHAR(50),
            sy VARCHAR(20), 
            sem VARCHAR(20), 
            pay_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            received_by INT
        );

        CREATE TABLE IF NOT EXISTS fee_schedules (
            id SERIAL PRIMARY KEY,
            fee_name VARCHAR(100),
            fee_type VARCHAR(20) DEFAULT 'Tuition',
            amount DECIMAL(10,2),
            sy VARCHAR(20),
            sem VARCHAR(20),
            student_id INT
        );

		CREATE TABLE IF NOT EXISTS tasks (
            id SERIAL PRIMARY KEY,
            student_id INT,
            task_content TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // DYNAMIC AUTO-PATCHER: Forces missing columns into existing tables without deleting data
    try { $pdo->exec("ALTER TABLE users ADD COLUMN course VARCHAR(150)"); } catch (PDOException $e) { }

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

// --- TASK LOGIC ---
if (isset($_POST['add_task'])) {
    $stmt = $pdo->prepare("INSERT INTO tasks (student_id, task_content) VALUES (?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['task_text']]);
    $redirect_page = $_POST['page'] ?? 'my_tasks';
    header("Location: ?page=" . $redirect_page); exit();
}
if (isset($_GET['del_task'])) {
    $pdo->prepare("DELETE FROM tasks WHERE id = ? AND student_id = ?")
        ->execute([$_GET['del_task'], $_SESSION['user_id']]);
    $redirect_page = $_GET['page'] ?? 'my_tasks';
    header("Location: ?page=" . $redirect_page); exit();
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
        } else { $msg = "<div class='alert alert-warning text-dark'>Your account is currently: " . strtoupper($user['status']) . "</div>"; }
    } else { $msg = "<div class='alert alert-danger text-dark'>Invalid credentials.</div>"; }
}

// STUDENT ONLY REGISTRATION LOGIC
if (isset($_POST['register_user'])) {
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $course = $_POST['course'] ?? 'Not Set';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, email, username, password, role, status, course) VALUES (?, ?, ?, ?, ?, 'student', 'pending', ?)");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['email'], $_POST['user'], $hash, $course]);
        
        $_SESSION['sys_msg'] = "<div class='alert alert-success text-dark'>Registration successful! Wait for Admin approval.</div>";
        header("Location: ?view=login");
        exit();
    } catch (PDOException $e) { 
        if ($e->errorInfo[1] == 1062) {
            $msg = "<div class='alert alert-danger text-dark'>Username or Email is already taken. Please try another.</div>";
        } else {
            $msg = "<div class='alert alert-danger text-dark'>Database Error: " . $e->getMessage() . "</div>";
        }
    }
}

// UNIFIED PERSONAL PROFILE & PASSWORD UPDATE ACTION HANDLER
if (isset($_POST['user_update_own_profile'])) {
    $photo_query = "";
    if(!empty($_FILES['photo']['name'])) {
        $photo_name = time() . "_" . $_FILES['photo']['name'];
        if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
        move_uploaded_file($_FILES['photo']['tmp_name'], "uploads/" . $photo_name);
        $photo_query = ", photo='$photo_name'";
    }

    $birthdate = !empty($_POST['bdate']) ? $_POST['bdate'] : null;

    if (!empty($_POST['new_password'])) {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET firstname=?, lastname=?, email=?, birthdate=?, address=?, password=? $photo_query WHERE id=?");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['email'], $birthdate, $_POST['addr'], $hash, $_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET firstname=?, lastname=?, email=?, birthdate=?, address=? $photo_query WHERE id=?");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['email'], $birthdate, $_POST['addr'], $_SESSION['user_id']]);
    }

    $_SESSION['name'] = $_POST['fname'] . " " . $_POST['lname'];
    $_SESSION['sys_msg'] = "<div class='alert alert-success text-dark'>Your dashboard profile information has been successfully updated!</div>";
    header("Location: ?page=home");
    exit();
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
        
        $birthdate = !empty($_POST['bdate']) ? $_POST['bdate'] : null;
        
        $stmt = $pdo->prepare("UPDATE users SET firstname=?, lastname=?, birthdate=?, address=? $photo_query WHERE id=?");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $birthdate, $_POST['addr'], $_POST['sid']]);
        $msg = "<div class='alert alert-success text-dark'>Information updated.</div>";
    }
    if (isset($_POST['records_update_grade'])) {
        $stmt = $pdo->prepare("UPDATE enrollments SET prelim=?, midterm=?, final=?, remarks=? WHERE id=?");
        $stmt->execute([$_POST['p'], $_POST['m'], $_POST['f'], $_POST['r'], $_POST['eid']]);
        $msg = "<div class='alert alert-success text-dark'>Academic records updated.</div>";
    }
}

// CASHIER ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'cashier') {
    if (isset($_POST['process_payment'])) {
        $receipt = "RCPT-" . time();
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, receipt_no, sy, sem, received_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['sid'], $_POST['amt'], $receipt, $_POST['sy'], $_POST['sem'], $_SESSION['user_id']]);
        $msg = "<div class='alert alert-success text-dark'>Payment Successful! Receipt: $receipt</div>";
    }
    if (isset($_GET['del_payment'])) {
        $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->execute([$_GET['del_payment']]);
        $msg = "<div class='alert alert-danger text-dark'>Payment record has been removed.</div>";
    }
}

// TEACHER ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'teacher') {
    if (isset($_POST['update_grades'])) {
        foreach($_POST['grades'] as $enrollment_id => $data) {
            $stmt = $pdo->prepare("UPDATE enrollments SET prelim=?, midterm=?, final=?, remarks=? WHERE id=?");
            $stmt->execute([$data['p'], $data['m'], $data['f'], $data['r'], $enrollment_id]);
        }
        $msg = "<div class='alert alert-success text-dark'>Grades updated successfully.</div>";
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
        $msg = "<div class='alert alert-success text-dark'>Subject data updated.</div>";
    }
    if (isset($_GET['del_sub'])) {
        $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([$_GET['del_sub']]);
        $msg = "<div class='alert alert-danger text-dark'>Subject deleted successfully.</div>";
    }
}

// FINANCE ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'finance') {
    if (isset($_POST['add_fee'])) {
        $student_id = ($_POST['target_student'] == "0") ? null : $_POST['target_student'];
        $stmt = $pdo->prepare("INSERT INTO fee_schedules (fee_name, fee_type, amount, sy, sem, student_id) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$_POST['fee_name'], $_POST['fee_type'], $_POST['amount'], $_POST['sy'], $_POST['sem'], $student_id]);
        $msg = "<div class='alert alert-success text-dark'>Fee schedule updated.</div>";
    }
    if (isset($_GET['del_fee'])) {
        $pdo->prepare("DELETE FROM fee_schedules WHERE id=?")->execute([$_GET['del_fee']]);
        $msg = "<div class='alert alert-danger text-dark'>Fee removed.</div>";
    }
}

// ADMIN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_GET['delete_user_id'])) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_GET['delete_user_id']]);
        $msg = "<div class='alert alert-danger text-dark'>User account has been permanently removed.</div>";
    }
    if (isset($_GET['approve_id'])) {
        $pdo->prepare("UPDATE users SET status='approved' WHERE id=?")->execute([$_GET['approve_id']]);
        $msg = "<div class='alert alert-success text-dark'>User Approved.</div>";
    }
    if (isset($_GET['reject_id'])) {
        $pdo->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$_GET['reject_id']]);
        $msg = "<div class='alert alert-danger text-dark'>User Rejected.</div>";
    }
    if (isset($_POST['create_staff'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status) VALUES (?, ?, ?, ?, ?, 'approved')")
            ->execute([$_POST['fname'], $_POST['lname'], $_POST['user'], $hash, $_POST['role']]);
        $msg = "<div class='alert alert-success text-dark'>Staff account created successfully.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Core Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <style>
       /* GLOBAL THEME */
       body { 
            font-family: 'Poppins', sans-serif; 
            background: url('https://images.unsplash.com/photo-1759434236990-3ce36b930edf?fm=jpg&q=60&w=3000&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D') no-repeat center center fixed;
            background-size: cover;
            color: #ffffff;
        }

        /* 2. GLASS PANELS - TRANSPARENT LIGHT BLACK */
        .glass-panel, .card {
            background: rgba(45, 45, 50, 0.35) !important; 
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #ffffff !important;
            border-radius: 8px;
        }

        /* 3. WHITE LABELS FOR GLOBAL TEXT */
        h1, h2, h3, h4, h5, h6, p, span, td, th {
            color: #ffffff;
        }

        /* 4. TABLES */
        .table { 
            color: #ffffff !important; 
            border-color: rgba(255, 255, 255, 0.1) !important;
            background: transparent !important;
        }
        .table :not(caption) > * > * {
            background-color: transparent !important;
            color: #ffffff !important;
        }
        .table-dark { background: rgba(0, 0, 0, 0.2) !important; }
        .table-hover tbody tr:hover { background-color: rgba(255, 255, 255, 0.05) !important; }

        /* 5. SIDEBAR - GLASSMORPHISM */
        .glass-sidebar {
            background: rgba(45, 55, 75, 0.35) !important; 
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }
        .glass-sidebar .offcanvas-body { padding: 0; }
        .glass-sidebar .profile-section {
            padding: 40px 20px 20px;
            text-align: center;
        }
        .glass-sidebar .profile-section img {
            border: 2px solid #d97736;
            width: 90px;
            height: 90px;
            object-fit: cover;
        }
        .glass-sidebar a { 
            color: #e0e0e0 !important; 
            text-decoration: none !important; 
            padding: 16px 24px;
            display: block;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05); 
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .glass-sidebar a i {
            margin-right: 12px;
            font-size: 1.15rem;
            vertical-align: text-bottom;
        }
        .glass-sidebar a:hover { 
            background: rgba(255, 255, 255, 0.08) !important; 
            color: #ffffff !important;
        }
        .glass-sidebar .logout-link {
            color: #ff6b6b !important;
            border-bottom: none;
            margin-top: 20px;
        }
        .glass-sidebar .logout-link:hover { background: rgba(255, 107, 107, 0.1) !important; }

        /* 6. INPUTS */
        .form-control, .form-select {
            background: rgba(42, 42, 48, 0.8) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
        }
        .form-control::placeholder { color: #aaaaaa !important; }

        /* 7. BUTTONS */
        .btn-orange {
            background: #d97736 !important;
            border: none !important;
            color: #ffffff !important;
        }

        /* 8. MODALS */
        .modal-content {
            background: rgba(45, 55, 75, 0.4) !important; 
            backdrop-filter: blur(25px) saturate(120%);
            -webkit-backdrop-filter: blur(25px) saturate(120%);
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 12px !important;
            box-shadow: 0 15px 35px rgba(0,0,0,0.4);
        }
        .modal-body { padding: 24px !important; }
        .modal-body .form-control {
            background-color: #2e3035 !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            border-radius: 6px !important;
            padding: 12px 16px !important;
            font-size: 0.95rem !important;
        }
        .modal-body .form-control::placeholder { color: rgba(255, 255, 255, 0.85) !important; }
        .modal-body textarea.form-control { min-height: 100px; resize: none; }
        .modal-body input[type="file"].form-control { padding: 0 !important; display: flex; align-items: center; background-color: #2e3035 !important; }
        .modal-body input[type="file"].form-control::file-selector-button {
            background-color: rgba(255, 255, 255, 0.15) !important;
            color: #ffffff !important;
            border: none !important;
            border-right: 1px solid rgba(255, 255, 255, 0.1) !important;
            padding: 12px 20px !important;
            margin-right: 15px !important;
            border-radius: 6px 0 0 6px !important;
            cursor: pointer;
            font-weight: 500;
        }
        .modal-footer { border-top: none !important; padding: 0 24px 24px 24px !important; background: transparent !important; }
        .modal-footer .btn-secondary { background-color: #5a6268 !important; border: none !important; color: #ffffff !important; padding: 10px 24px !important; font-size: 0.95rem !important; border-radius: 6px !important; }
        .modal-footer .btn-orange { background-color: #d97736 !important; padding: 10px 24px !important; font-size: 0.95rem !important; border-radius: 6px !important; }

        /* MUSIC SYSTEM DECK STYLING (Cloning image_486b26.png layout directly) */
        .deck-wrapper {
            background: #1e1e1e !important;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .track-timeline-slider {
            -webkit-appearance: none;
            width: 100%;
            height: 4px;
            border-radius: 2px;
            background: #444;
            outline: none;
            cursor: pointer;
            transition: background 0.1s;
        }
        .track-timeline-slider::-webkit-slider-runnable-track {
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, #ff0033 0%, #ff0033 var(--seek-percent, 0%), #444 var(--seek-percent, 0%), #444 100%);
            border-radius: 2px;
        }
        .track-timeline-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ff0033;
            cursor: pointer;
            margin-top: -5px;
            box-shadow: 0 0 4px rgba(0,0,0,0.5);
        }
        .deck-playback-btn {
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 1.4rem;
            cursor: pointer;
            opacity: 0.85;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .deck-playback-btn:hover { opacity: 1; transform: scale(1.05); }
        .deck-playback-btn.active { color: #ff0033 !important; }
        .deck-master-play-btn {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 50%;
            font-size: 1.8rem;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        }
        .deck-master-play-btn:hover { background: rgba(255,255,255,0.18); transform: scale(1.03); }
        
        .track-item {
            background: rgba(255, 255, 255, 0.04);
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .track-item:hover, .track-item.active { background: rgba(217, 119, 54, 0.15); }

        /* POMODORO TIMER PANEL STYLING */
        .timer-preset-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .timer-preset-btn:hover, .timer-preset-btn.active {
            background: #d97736;
            border-color: #d97736;
        }

        /* UTILS */
        .text-muted { color: rgba(255,255,255,0.7) !important; }
        a { color: #d97736; }
        a:hover { color: #b8622b; }

        @media (min-width: 768px) { .sidebar-wrapper { min-height: 100vh; } }
    </style>
</head>
<body>
<?php if (!isset($_SESSION['user_id'])): ?>
    <div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="glass-panel login-box" style="padding: 40px; border-radius: 15px;">
            <?= $msg ?>
            <?php if ($view == 'login'): ?>
                <h3 class="text-center fw-semibold mb-4" style="letter-spacing: 1px;">LOGIN</h3>
                <form method="POST">
                    <div class="mb-4">
                        <input type="text" name="username" class="form-control glass-input-login" placeholder="User Name" required autocomplete="off">
                    </div>
                    <div class="mb-4">
                        <input type="password" name="password" class="form-control glass-input-login" placeholder="Password" required>
                    </div>
                    <div class="terms-wrapper mb-3 text-white">
                        <input type="checkbox" id="terms" required>
                        <label for="terms">I agree to the terms and conditions</label>
                    </div>
                    <button name="login" class="btn btn-orange w-100 py-2">Login</button>
                </form>
                <div class="text-center mt-4">
                    <a href="?view=register" class="small text-decoration-none text-white text-decoration-underline">Apply for a Student Account</a>
                </div>
            <?php else: ?>
                <h3 class="text-center fw-semibold mb-4" style="font-size: 1.5rem;">Create Account</h3>
                <form method="POST" autocomplete="off">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><input name="fname" placeholder="First Name" class="form-control" required></div>
                        <div class="col-md-6"><input name="lname" placeholder="Last Name" class="form-control" required></div>
                    </div>
                    <input name="email" type="email" placeholder="Email Address" class="form-control mb-3" required>

                    <select name="course" class="form-select custom-dark-select mb-3" required>
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
							<option value="Bachelor of Early Childhood Education">Bachelor of Early Childhood Education (BECED)</option>
                        </optgroup>
                    </select>

                    <input name="user" placeholder="Desired Username" class="form-control mb-3" required autocomplete="off">
                    <input name="password" type="password" placeholder="Password" class="form-control mb-4" required autocomplete="new-password">
                    <button name="register_user" class="btn btn-orange w-100 py-2">Submit Application</button>
                </form>
                <div class="text-center mt-3">
                    <a href="?view=login" class="small text-decoration-none text-white text-decoration-underline">Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <nav class="navbar navbar-dark bg-dark d-md-none mobile-nav no-print" style="background: rgba(0,0,0,0.8) !important;">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Campus Core</span>
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 p-0 no-print sidebar-wrapper offcanvas-md offcanvas-start glass-sidebar" tabindex="-1" id="sidebarMenu">
                <div class="offcanvas-header d-md-none border-bottom border-secondary text-white">
                    <h5 class="offcanvas-title">Menu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button>
                </div>
                <div class="offcanvas-body d-flex flex-column p-0">
                    <div class="profile-section">
                        <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle mb-2 shadow">
                        <h6 class="mb-1"><?= $_SESSION['name'] ?></h6>
                        <small style="color: #d97736; font-weight: 500; letter-spacing: 1px;"><?= strtoupper($_SESSION['role']) ?></small>
                    </div>
                    
                    <a href="?page=home"><i class="bi bi-house"></i> Home</a>
                    <?php if($_SESSION['role'] == 'admin'): ?>
                        <a href="?page=approvals"><i class="bi bi-person-check"></i> User Approvals</a>
                        <a href="?page=create_staff"><i class="bi bi-person-plus"></i> Create Staff</a>
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
                        <a href="?page=my_tasks"><i class="bi bi-list-check"></i> My Tasks & Music</a>
                        <a href="?page=my_permit"><i class="bi bi-ticket-perforated"></i> Exam Permit</a>
                    <?php endif; ?>
                    <a href="?action=logout" class="logout-link"><i class="bi bi-power"></i> Logout</a>
                </div>
            </div>

            <div class="col-md-10 p-3 p-md-4">
                <?= $msg ?>
                <?php
                $page = $_GET['page'] ?? 'home';
                
               // --- HOME DASHBOARD ---
                if ($page == 'home') {
                    ?>
                    <h3 class="mb-4"><?= ucfirst($_SESSION['role']) ?> Dashboard</h3>
                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <div class="glass-panel p-4 h-100">
                                <div class="text-center mb-4">
                                    <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle shadow-sm" style="width:130px; height:130px; object-fit:cover; border: 2px solid #d97736;">
                                    <h5 class="mt-3 mb-0 fw-semibold"><?= $currentUser['firstname'] ?> <?= $currentUser['lastname'] ?></h5>
                                    <small class="text-white-50">Account Reference ID: REF-00<?= $currentUser['id'] ?></small>
                                    <div class="mt-3">
                                        <button class="btn btn-sm btn-orange px-4 py-2 rounded" data-bs-toggle="modal" data-bs-target="#editMyOwnProfile">
                                            <i class="bi bi-pencil me-1"></i> Edit Profile
                                        </button>
                                    </div>
                                </div>
                                <div class="text-start mt-4" style="font-size: 0.9rem;">
                                    <p class="mb-2"><strong style="color: #e0e0e0;">Email:</strong> <?= $currentUser['email'] ?: 'Not set' ?></p>
                                    <p class="mb-2"><strong style="color: #e0e0e0;">Role Privileges:</strong> <?= strtoupper($currentUser['role']) ?></p>
                                    <p class="mb-0"><strong style="color: #e0e0e0;">Address:</strong> <?= $currentUser['address'] ?: 'Not set' ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="editMyOwnProfile" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <form method="POST" enctype="multipart/form-data" class="modal-content">
                                    <div class="modal-body">
                                        <div class="row g-3 mb-3">
                                            <div class="col-6">
                                                <input type="text" name="fname" value="<?= htmlspecialchars($currentUser['firstname']) ?>" class="form-control" placeholder="First Name" required>
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="lname" value="<?= htmlspecialchars($currentUser['lastname']) ?>" class="form-control" placeholder="Last Name" required>
                                            </div>
                                        </div>
                                        
                                        <input type="email" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" class="form-control mb-3" placeholder="Email Address" required>
                                        
                                        <input type="password" name="new_password" class="form-control mb-3" placeholder="New Password">
                                        
                                        <input type="date" name="bdate" value="<?= $currentUser['birthdate'] ?>" class="form-control mb-3" placeholder="dd/mm/yyyy">
                                        
                                        <textarea name="addr" class="form-control mb-3" placeholder="Address"><?= htmlspecialchars($currentUser['address'] ?? '') ?></textarea>
                                        
                                        <input type="file" name="photo" class="form-control" accept="image/*">
                                    </div>
                                    <div class="modal-footer justify-content-end">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button name="user_update_own_profile" class="btn btn-orange">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="mb-3">
                                <h4 class="fw-semibold">Welcome back, <?= $currentUser['firstname'] ?>!</h4>
                                <p class="text-white opacity-75 small">Use the dashboard sidebar matrix panel on the left to navigate your operations infrastructure tools.</p>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                // --- MY TASKS & WORKSPACE DASHBOARD (With Custom Player image_486b26.png & Timer) ---
                elseif ($page == 'my_tasks' && $_SESSION['role'] == 'student') {
                    ?>
                    <h3 class="mb-4">Workspace Hub</h3>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="glass-panel p-4 mb-4" id="tasks">
                                <h5 class="mb-3 fw-semibold"><i class="bi bi-list-check me-2"></i>Task Manager</h5>
                                <form method="POST" class="d-flex mb-4">
                                    <input type="hidden" name="page" value="my_tasks">
                                    <input type="text" name="task_text" class="form-control me-2 py-2" placeholder="Enter a new task..." required>
                                    <button name="add_task" class="btn btn-orange px-4 rounded">Add</button>
                                </form>
                                <ul class="list-group" style="max-height: 280px; overflow-y: auto;">
                                    <?php
                                    $tasks = $pdo->prepare("SELECT * FROM tasks WHERE student_id = ? ORDER BY created_at DESC");
                                    $tasks->execute([$_SESSION['user_id']]);
                                    foreach($tasks as $t): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center mb-2 rounded border-0" style="background: rgba(255,255,255,0.08); color: white;">
                                            <?= htmlspecialchars($t['task_content']) ?>
                                            <a href="?page=my_tasks&del_task=<?= $t['id'] ?>" class="btn btn-sm btn-danger text-white"><i class="bi bi-trash"></i></a>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if($tasks->rowCount() == 0): ?>
                                        <li class="list-group-item text-white opacity-75 text-center py-4" style="background: transparent; border: none;">No current tasks.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <div class="glass-panel p-4">
                                <h5 class="mb-3 fw-semibold"><i class="bi bi-hourglass-split me-2"></i>Study Timer Mode</h5>
                                <div class="d-flex flex-wrap justify-content-center gap-2 mb-3 align-items-center">
                                    <button onclick="setTimerPreset(25)" class="timer-preset-btn active" id="btnPresetStudy">25m Study</button>
                                    <button onclick="setTimerPreset(5)" class="timer-preset-btn" id="btnPresetShort">5m Break</button>
                                    <button onclick="setTimerPreset(15)" class="timer-preset-btn" id="btnPresetLong">15m Break</button>
                                    <div class="input-group" style="width: 140px;">
                                        <input type="number" id="customTimerInput" class="form-control" placeholder="Mins" min="1">
                                        <button onclick="setCustomTimer()" class="btn btn-outline-light timer-preset-btn border-start-0" style="border-radius: 0 20px 20px 0;">Set</button>
                                    </div>
                                </div>
                                <div class="text-center my-4">
                                    <div id="countdownClockDisplay" class="fw-semibold display-3" style="font-family: monospace; letter-spacing: 2px;">25:00</div>
                                </div>
                                <div class="d-flex justify-content-center gap-3">
                                    <button id="btnTimerControl" onclick="toggleTimerCore()" class="btn btn-orange px-4"><i class="bi bi-play-fill me-1"></i>Start</button>
                                    <button onclick="resetTimerCore()" class="btn btn-outline-light px-4"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 mb-4">
                            <div class="deck-wrapper mb-4">
                                <div id="trackDeckMetaTitle" class="text-truncate text-center small text-white-50 mb-3" style="letter-spacing: 0.5px;">No Local File Loaded</div>
                                
                                <div class="d-flex align-items-center justify-content-between px-1 mb-2">
                                    <span id="deckTimeElapsed" style="font-size: 0.8rem; font-family: monospace; opacity:0.8;">00:00</span>
                                    <span id="deckTimeRemaining" style="font-size: 0.8rem; font-family: monospace; opacity:0.8;">- 00:00</span>
                                </div>
                                <div class="px-1 mb-4">
                                    <input type="range" id="deckTimelineSeeker" class="track-timeline-slider" value="0" min="0" max="100" step="0.1" oninput="manualDeckSeek(this.value)">
                                </div>

                                <div class="d-flex align-items-center justify-content-between px-3">
                                    <button onclick="toggleDeckShuffle()" id="btnDeckShuffle" class="deck-playback-btn" title="Toggle Shuffle">
                                        <i class="bi bi-shuffle"></i>
                                    </button>
                                    <button onclick="prevDeckTrack()" class="deck-playback-btn" title="Previous Track">
                                        <i class="bi bi-skip-start-fill"></i>
                                    </button>
                                    
                                    <button onclick="toggleDeckPlayback()" id="btnMasterDeckPlay" class="deck-master-play-btn" title="Play/Pause">
                                        <i class="bi bi-play-fill" style="margin-left: 3px;"></i>
                                    </button>
                                    
                                    <button onclick="nextDeckTrack()" class="deck-playback-btn" title="Next Track">
                                        <i class="bi bi-skip-end-fill"></i>
                                    </button>
                                    <button onclick="toggleVolumePopover()" id="btnDeckSliders" class="deck-playback-btn" title="Volume Sliders">
                                        <i class="bi bi-sliders"></i>
                                    </button>
                                </div>

                                <div id="volumeSliderPane" class="mt-3 px-2 d-none transition">
                                    <div class="d-flex align-items-center gap-2 bg-dark p-2 rounded">
                                        <i class="bi bi-volume-up-fill text-white-50 small"></i>
                                        <input type="range" class="form-range" min="0" max="1" step="0.05" value="0.8" oninput="changeDeckVolume(this.value)">
                                    </div>
                                </div>
                            </div>

                            <div class="glass-panel p-4">
                                <h6 class="small fw-semibold text-white-50 mb-2"><i class="bi bi-folder-plus me-1"></i>Import Audio Files</h6>
                                <div class="mb-4">
                                    <input type="file" id="localAudioPicker" class="form-control form-control-sm" accept="audio/*" multiple onchange="loadFilesIntoPlaylist(this)">
                                </div>

                                <div class="playlist-vault-box overflow-auto" style="max-height: 200px;">
                                    <div id="deckPlaylistTracksContainer" class="d-flex flex-column gap-2">
                                        </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        // AUDIO INFRASTRUCTURE ENGINE DECK MATRIX
                        const coreAudioNode = new Audio();
                        let originalPlaylistQueue = [];
                        let activePlaylistQueue = [];
                        let currentQueueIndex = -1;
                        let isShuffleActive = false;

                        // Deck Tracking Element Reference Pointers
                        const deckSeeker = document.getElementById('deckTimelineSeeker');
                        const deckElapsedText = document.getElementById('deckTimeElapsed');
                        const deckRemainingText = document.getElementById('deckTimeRemaining');
                        const masterPlayBtn = document.getElementById('btnMasterDeckPlay');
                        const trackMetaTitle = document.getElementById('trackDeckMetaTitle');

                        function loadFilesIntoPlaylist(inputNode) {
                            if(!inputNode.files || inputNode.files.length === 0) return;

                            for(let i = 0; i < inputNode.files.length; i++) {
                                const file = inputNode.files[i];
                                const trackBlobUrl = URL.createObjectURL(file);
                                const cleanTitle = file.name.replace(/\.[^/.]+$/, ""); // Strip file extensions

                                const trackObj = { title: cleanTitle, url: trackBlobUrl };
                                originalPlaylistQueue.push(trackObj);
                            }

                            rebuildActiveQueueChain();
                            renderDeckPlaylistUI();
                            inputNode.value = ""; // Clear file picker loop reference
                        }

                        function rebuildActiveQueueChain() {
                            if (!isShuffleActive) {
                                activePlaylistQueue = [...originalPlaylistQueue];
                            } else {
                                // Classic modern Fisher-Yates array randomizer loop configuration
                                activePlaylistQueue = [...originalPlaylistQueue];
                                for (let i = activePlaylistQueue.length - 1; i > 0; i--) {
                                    const j = Math.floor(Math.random() * (i + 1));
                                    [activePlaylistQueue[i], activePlaylistQueue[j]] = [activePlaylistQueue[j], activePlaylistQueue[i]];
                                }
                            }
                        }

                        function renderDeckPlaylistUI() {
                            const container = document.getElementById('deckPlaylistTracksContainer');
                            container.innerHTML = '';

                            if(originalPlaylistQueue.length === 0) {
                                container.innerHTML = '<div class="text-center py-3 text-white-50 small">No local files added yet.</div>';
                                return;
                            }

                            // Render tracks based on the original list for visual stability
                            originalPlaylistQueue.forEach((track) => {
                                // Match indexing to active queue map tracking positions
                                let activeIdx = activePlaylistQueue.findIndex(t => t.url === track.url);
                                const isCurrent = activeIdx === currentQueueIndex && currentQueueIndex !== -1;
                                const currentClass = isCurrent ? 'active fw-bold' : '';

                                const node = document.createElement('div');
                                node.className = `track-item d-flex justify-content-between align-items-center ${currentClass}`;
                                node.onclick = (e) => {
                                    if(e.target.closest('.btn-purge-track')) return;
                                    fireTrackPlaybackByIndex(activeIdx);
                                };

                                node.innerHTML = `
                                    <div class="text-truncate ps-1 small"><i class="bi bi-music-note me-2 opacity-50"></i>${track.title}</div>
                                    <button class="btn btn-sm text-danger btn-purge-track p-1" onclick="purgeTrackFromVault('${track.url}')"><i class="bi bi-trash"></i></button>
                                `;
                                container.appendChild(node);
                            });
                        }

                        function fireTrackPlaybackByIndex(targetIdx) {
                            if(targetIdx < 0 || targetIdx >= activePlaylistQueue.length) return;
                            currentQueueIndex = targetIdx;

                            coreAudioNode.src = activePlaylistQueue[currentQueueIndex].url;
                            trackMetaTitle.innerText = activePlaylistQueue[currentQueueIndex].title;
                            
                            coreAudioNode.play().catch(err => console.log("Playback initialized safely."));
                            masterPlayBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
                            renderDeckPlaylistUI();
                        }

                        function toggleDeckPlayback() {
                            if(activePlaylistQueue.length === 0) return;
                            if(currentQueueIndex === -1) {
                                fireTrackPlaybackByIndex(0);
                                return;
                            }

                            if(coreAudioNode.paused) {
                                coreAudioNode.play();
                                masterPlayBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
                            } else {
                                coreAudioNode.pause();
                                masterPlayBtn.innerHTML = '<i class="bi bi-play-fill" style="margin-left: 3px;"></i>';
                            }
                        }

                        function nextDeckTrack() {
                            if(activePlaylistQueue.length === 0) return;
                            let idx = currentQueueIndex + 1;
                            if(idx >= activePlaylistQueue.length) idx = 0;
                            fireTrackPlaybackByIndex(idx);
                        }

                        function prevDeckTrack() {
                            if(activePlaylistQueue.length === 0) return;
                            let idx = currentQueueIndex - 1;
                            if(idx < 0) idx = activePlaylistQueue.length - 1;
                            fireTrackPlaybackByIndex(idx);
                        }

                        function toggleDeckShuffle() {
                            isShuffleActive = !isShuffleActive;
                            const btn = document.getElementById('btnDeckShuffle');
                            
                            // Track the current track to keep it playing seamlessly
                            let currentTrackObj = currentQueueIndex !== -1 ? activePlaylistQueue[currentQueueIndex] : null;
                            
                            if(isShuffleActive) btn.classList.add('active');
                            else btn.classList.remove('active');

                            rebuildActiveQueueChain();

                            if(currentTrackObj) {
                                currentQueueIndex = activePlaylistQueue.findIndex(t => t.url === currentTrackObj.url);
                            }
                            renderDeckPlaylistUI();
                        }

                        function purgeTrackFromVault(targetUrl) {
                            let trackObj = originalPlaylistQueue.find(t => t.url === targetUrl);
                            if(!trackObj) return;

                            let activeIdx = activePlaylistQueue.findIndex(t => t.url === targetUrl);
                            originalPlaylistQueue = originalPlaylistQueue.filter(t => t.url !== targetUrl);
                            
                            if(activeIdx === currentQueueIndex && currentQueueIndex !== -1) {
                                coreAudioNode.pause();
                                coreAudioNode.src = '';
                                trackMetaTitle.innerText = "No Local File Loaded";
                                currentQueueIndex = -1;
                                masterPlayBtn.innerHTML = '<i class="bi bi-play-fill" style="margin-left: 3px;"></i>';
                            }

                            rebuildActiveQueueChain();
                            if(currentQueueIndex !== -1 && activeIdx < currentQueueIndex) {
                                currentQueueIndex--;
                            }
                            renderDeckPlaylistUI();
                        }

                        function manualDeckSeek(val) {
                            if(!coreAudioNode.duration) return;
                            coreAudioNode.currentTime = (val / 100) * coreAudioNode.duration;
                        }

                        function changeDeckVolume(val) {
                            coreAudioNode.volume = val;
                        }

                        function toggleVolumePopover() {
                            const pane = document.getElementById('volumeSliderPane');
                            pane.classList.toggle('d-none');
                        }

                        function timeFormatMap(secs) {
                            if(isNaN(secs)) return "00:00";
                            let m = Math.floor(secs / 60);
                            let s = Math.floor(secs % 60);
                            return (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
                        }

                        // Audio Event Bindings
                        coreAudioNode.ontimeupdate = () => {
                            if(!coreAudioNode.duration) return;
                            
                            const elapsed = coreAudioNode.currentTime;
                            const duration = coreAudioNode.duration;
                            const pct = (elapsed / duration) * 100;

                            deckSeeker.value = pct;
                            deckSeeker.style.setProperty('--seek-percent', pct + '%');
                            
                            deckElapsedText.innerText = timeFormatMap(elapsed);
                            deckRemainingText.innerText = "- " + timeFormatMap(duration - elapsed);
                        };

                        coreAudioNode.onended = () => { nextDeckTrack(); };


                        // STUDY WORKSPACE POMODORO COUNTER SYSTEM ENGINE LOGIC
                        let timerEngineRunning = false;
                        let timerLoopHandle = null;
                        let currentBaseSeconds = 1500; // Store the chosen base time so we can reset to it
                        let targetDurationSeconds = 1500; // Default 25m

                        const clockDisplay = document.getElementById('countdownClockDisplay');
                        const timerControlBtn = document.getElementById('btnTimerControl');

                        function setTimerPreset(mins) {
                            currentBaseSeconds = mins * 60;
                            document.querySelectorAll('.timer-preset-btn').forEach(btn => btn.classList.remove('active'));
                            
                            if(mins === 25) document.getElementById('btnPresetStudy').classList.add('active');
                            if(mins === 5) document.getElementById('btnPresetShort').classList.add('active');
                            if(mins === 15) document.getElementById('btnPresetLong').classList.add('active');
                            
                            resetTimerCore();
                        }

                        function setCustomTimer() {
                            const mins = parseInt(document.getElementById('customTimerInput').value);
                            if (mins && mins > 0) {
                                currentBaseSeconds = mins * 60;
                                document.querySelectorAll('.timer-preset-btn').forEach(btn => btn.classList.remove('active'));
                                resetTimerCore();
                                document.getElementById('customTimerInput').value = ''; // clear input after setting
                            }
                        }

                        function toggleTimerCore() {
                            if(timerEngineRunning) {
                                // Pause loop pipeline step execution tracking sequence context map
                                clearInterval(timerLoopHandle);
                                timerEngineRunning = false;
                                timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
                            } else {
                                timerEngineRunning = true;
                                timerControlBtn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>Pause';
                                
                                timerLoopHandle = setInterval(() => {
                                    if(targetDurationSeconds <= 0) {
                                        clearInterval(timerLoopHandle);
                                        timerEngineRunning = false;
                                        timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
                                        alert("Study timer session complete! Resetting block intervals.");
                                        resetTimerCore();
                                        return;
                                    }
                                    targetDurationSeconds--;
                                    
                                    let mins = Math.floor(targetDurationSeconds / 60);
                                    let secs = Math.floor(targetDurationSeconds % 60);
                                    clockDisplay.innerText = (mins < 10 ? "0" : "") + mins + ":" + (secs < 10 ? "0" : "") + secs;
                                }, 1000);
                            }
                        }

                        function resetTimerCore() {
                            clearInterval(timerLoopHandle);
                            timerEngineRunning = false;
                            timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
                            
                            targetDurationSeconds = currentBaseSeconds;

                            let mins = Math.floor(targetDurationSeconds / 60);
                            let secs = Math.floor(targetDurationSeconds % 60);
                            clockDisplay.innerText = (mins < 10 ? "0" : "") + mins + ":" + (secs < 10 ? "0" : "") + secs;
                        }
                    </script>
                    <?php
                }
                // --- RECORDS PAGES ---
                elseif ($page == 'rec_students' && $_SESSION['role'] == 'records') {
                    echo "<h3>Student Information Management</h3>";
                    $students = $pdo->query("SELECT * FROM users WHERE role='student'")->fetchAll();
                    ?>
                    <div class="table-responsive glass-panel p-2 mt-3">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark"><tr><th>Photo</th><th>Name</th><th>Birthdate</th><th>Address</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach($students as $s): ?>
                            <tr>
                                <td><img src="uploads/<?= $s['photo'] ?>" width="40" height="40" class="rounded-circle border border-secondary"></td>
                                <td><?= $s['lastname'] ?>, <?= $s['firstname'] ?></td>
                                <td><?= $s['birthdate'] ?></td>
                                <td><?= $s['address'] ?></td>
                                <td>
                                    <button class="btn btn-sm btn-orange" data-bs-toggle="modal" data-bs-target="#editS<?= $s['id'] ?>">Edit Info</button>
                                    <div class="modal fade" id="editS<?= $s['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog"><form method="POST" enctype="multipart/form-data" class="modal-content">
                                            <div class="modal-header border-0"><h5>Edit Student</h5></div>
                                            <div class="modal-body text-start">
                                                <input type="hidden" name="sid" value="<?= $s['id'] ?>">
                                                <label class="text-dark">First Name</label><input name="fname" value="<?= $s['firstname'] ?>" class="form-control mb-2">
                                                <label class="text-dark">Last Name</label><input name="lname" value="<?= $s['lastname'] ?>" class="form-control mb-2">
                                                <label class="text-dark">Birthdate</label><input type="date" name="bdate" value="<?= $s['birthdate'] ?>" class="form-control mb-2">
                                                <label class="text-dark">Address</label><textarea name="addr" class="form-control mb-2"><?= $s['address'] ?></textarea>
                                                <label class="text-dark">Picture</label><input type="file" name="photo" class="form-control">
                                            </div>
                                            <div class="modal-footer"><button name="update_student_profile" class="btn btn-success">Save</button></div>
                                        </form></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'rec_tor' && $_SESSION['role'] == 'records') {
                    echo "<h3>Transcript of Records (TOR)</h3>";
                    ?>
                    <div class="glass-panel p-3 mb-4 no-print">
                        <form method="GET" class="row g-2">
                            <input type="hidden" name="page" value="rec_tor">
                            <div class="col-md-9">
                                <select name="student_id" class="form-select custom-dark-select" required>
                                    <option value="">-- Select Student --</option>
                                    <?php 
                                    $st = $pdo->query("SELECT id, lastname, firstname FROM users WHERE role='student'")->fetchAll();
                                    foreach($st as $s) echo "<option value='{$s['id']}'>{$s['lastname']}, {$s['firstname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3"><button class="btn btn-orange w-100">Load TOR</button></div>
                        </form>
                    </div>
                    <?php if (!empty($_GET['student_id'])): 
                        $sid = $_GET['student_id'];
                        $stud = $pdo->prepare("SELECT * FROM users WHERE id=?"); $stud->execute([$sid]); $si = $stud->fetch();
                        $grades = $pdo->prepare("SELECT e.*, s.subject_code, s.subject_title, s.units, s.sy, s.sem FROM enrollments e JOIN subjects s ON e.subject_id = s.id WHERE e.student_id = ? ORDER BY s.sy ASC, s.sem ASC");
                        $grades->execute([$sid]);
                        $all_grades = $grades->fetchAll();
                    ?>
                        <div class="glass-panel p-3 p-md-5">
                            <div class="text-center mb-4">
                                <h2>TRANSCRIPT OF RECORDS</h2>
                                <hr style="border-color: rgba(255,255,255,0.2);">
                                <div class="row text-start mt-4">
                                    <div class="col-6"><strong>Name:</strong> <?= $si['lastname'] ?>, <?= $si['firstname'] ?></div>
                                    <div class="col-6"><strong>Address:</strong> <?= $si['address'] ?></div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-dark"><tr><th>SY/Sem</th><th>Code</th><th>Subject</th><th>Units</th><th>P</th><th>M</th><th>F</th><th>Remarks</th><th class="no-print">Edit</th></tr></thead>
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
                                                    <div class="modal-header border-0"><h6 class="text-dark">Edit Grade</h6></div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="eid" value="<?= $g['id'] ?>">
                                                        <div class="row g-2 mb-2">
                                                            <div class="col-4"><label class="text-dark">P</label><input name="p" value="<?= $g['prelim'] ?>" class="form-control"></div>
                                                            <div class="col-4"><label class="text-dark">M</label><input name="m" value="<?= $g['midterm'] ?>" class="form-control"></div>
                                                            <div class="col-4"><label class="text-dark">F</label><input name="f" value="<?= $g['final'] ?>" class="form-control"></div>
                                                        </div>
                                                        <label class="text-dark">Remarks</label><input name="r" value="<?= $g['remarks'] ?>" class="form-control">
                                                    </div>
                                                    <div class="modal-footer"><button name="records_update_grade" class="btn btn-orange btn-sm">Update</button></div>
                                                </form></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-3 no-print"><button onclick="window.print()" class="btn btn-secondary">Print PDF View</button></div>
                        </div>
                    <?php endif;
                }
                // --- CASHIER PAGES ---
                elseif ($page == 'cashier_billing' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Student Payables & Balance Summary</h3>";
                    ?>
                    <div class="glass-panel p-2 mt-3 table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr><th>Student Name</th><th>Total Assessment</th><th>Amount Paid</th><th>Balance Due</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student' ORDER BY lastname ASC")->fetchAll();
                                foreach($students as $b): 
                                    $load_stmt = $pdo->prepare("SELECT SUM(amount) FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                                    $load_stmt->execute([$b['id']]);
                                    $total_assessment = $load_stmt->fetchColumn() ?: 0;
                                    
                                    $pay_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
                                    $pay_stmt->execute([$b['id']]);
                                    $total_paid = $pay_stmt->fetchColumn();
                                    
                                    $balance = $total_assessment - $total_paid;
                                    $status_color = ($balance <= 0) ? 'success' : 'danger';
                                    $status_text = ($balance <= 0) ? 'Fully Paid' : 'With Balance';
                                ?>
                                <tr>
                                    <td><?= $b['lastname'] ?>, <?= $b['firstname'] ?></td>
                                    <td>₱<?= number_format($total_assessment, 2) ?></td>
                                    <td class="text-success fw-bold">₱<?= number_format($total_paid, 2) ?></td>
                                    <td class="fw-bold" style="color:#ff6b6b;">₱<?= number_format($balance, 2) ?></td>
                                    <td><span class="badge bg-<?= $status_color ?>"><?= $status_text ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'cashier_payments' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Receive Payments</h3>";
                    ?>
                    <div class="glass-panel p-4 mb-4">
                        <form method="POST" class="row g-3">
                            <div class="col-md-4">
                                <label>Select Student</label>
                                <select name="sid" class="form-select custom-dark-select" required>
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
                    <div class="glass-panel p-2 table-responsive">
                        <table class="table mb-0">
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
                                        <button onclick="window.print()" class="btn btn-sm btn-outline-light"><i class="bi bi-printer"></i></button>
                                        <a href="?page=cashier_payments&del_payment=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this payment?')"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'cashier_reports' && $_SESSION['role'] == 'cashier') {
                    echo "<h3>Cash Collection Reports</h3>";
                    ?>
                    <form method="POST" class="row g-2 mb-4 no-print glass-panel p-3 mx-0">
                        <div class="col-md-3"><input type="date" name="d1" class="form-control" required></div>
                        <div class="col-md-3"><input type="date" name="d2" class="form-control" required></div>
                        <div class="col-md-2"><button name="gen_report" class="btn btn-orange w-100">Show Report</button></div>
                    </form>
                    <?php
                    if (isset($_POST['gen_report'])) {
                        $stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname FROM payments p JOIN users u ON p.student_id = u.id WHERE DATE(pay_date) BETWEEN ? AND ?");
                        $stmt->execute([$_POST['d1'], $_POST['d2']]);
                        $results = $stmt->fetchAll();
                        echo "<h5>Collection from {$_POST['d1']} to {$_POST['d2']}</h5>";
                        echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>Date</th><th>Student</th><th>Receipt</th><th>Amount</th></tr></thead>";
                        $total = 0;
                        foreach($results as $res) {
                            echo "<tr><td>{$res['pay_date']}</td><td>{$res['lastname']}</td><td>{$res['receipt_no']}</td><td>₱".number_format($res['amount'],2)."</td></tr>";
                            $total += $res['amount'];
                        }
                        echo "<tr><th colspan='3' class='text-end'>TOTAL COLLECTION:</th><th style='color:#d97736;'>₱".number_format($total,2)."</th></tr></table></div>";
                        echo "<button onclick='window.print()' class='btn btn-secondary mt-3 no-print'>Print Report</button>";
                    }
                }
                // --- ADMIN PAGES ---
                elseif ($page == 'approvals' && $_SESSION['role'] == 'admin') {
                    echo "<h3>User Management</h3>";
                    $users = $pdo->query("SELECT * FROM users WHERE role != 'admin'")->fetchAll();
                    echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'>
                            <thead class='table-dark'><tr><th>Name</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>";
                    foreach($users as $p) {
                        echo "<tr>
                                <td>{$p['firstname']} {$p['lastname']}</td>
                                <td>{$p['role']}</td>
                                <td>".strtoupper($p['status'])."</td>
                                <td>";
                        if($p['status'] == 'pending') {
                            echo "<a href='?page=approvals&approve_id={$p['id']}' class='btn btn-sm btn-success'>Approve</a> ";
                        }
                        echo "<a href='?page=approvals&delete_user_id={$p['id']}' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'>Delete</a>
                              </td></tr>";
                    }
                    echo "</table></div>";
                }
                elseif ($page == 'create_staff' && $_SESSION['role'] == 'admin') {
                    echo "<h3>Create Staff Account</h3>";
                    ?>
                    <div class="glass-panel p-4" style="max-width: 500px;">
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
                            <button name="create_staff" class="btn btn-orange w-100">Register Staff</button>
                        </form>
                    </div>
                    <?php
                }
                // --- TEACHER PAGES ---
                elseif ($page == 'teacher_classes' && $_SESSION['role'] == 'teacher') {
                    echo "<h3>My Assigned Classes</h3>";
                    $classes = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ?");
                    $classes->execute([$_SESSION['user_id']]);
                    echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>Code</th><th>Title</th><th>Course</th><th>Schedule</th></tr></thead>";
                    foreach($classes->fetchAll() as $c) echo "<tr><td>{$c['subject_code']}</td><td>{$c['subject_title']}</td><td>{$c['course']}</td><td>{$c['schedule']}</td></tr>";
                    echo "</table></div>";
                }
                elseif ($page == 'teacher_grades' && $_SESSION['role'] == 'teacher') {
                    echo "<h3>Grade Encoding</h3>";
                    $q = $pdo->prepare("SELECT e.*, u.firstname, u.lastname, s.subject_title, s.course FROM enrollments e JOIN users u ON e.student_id = u.id JOIN subjects s ON e.subject_id = s.id WHERE s.teacher_id = ? ORDER BY s.course ASC, u.lastname ASC");
                    $q->execute([$_SESSION['user_id']]);
                    $list = $q->fetchAll(PDO::FETCH_ASSOC); 
                    
                    if (empty($list)) { 
                        echo "<div class='alert alert-info text-dark mt-3'>No students enrolled in your subjects.</div>"; 
                    } else {
                        ?>
                        <form method="POST">
                            <?php 
                            $current_course = null; 
                            foreach($list as $s): 
                                if ($current_course !== $s['course']): 
                                    if ($current_course !== null) echo "</tbody></table></div>"; 
                                    $current_course = $s['course'];
                            ?>
                                <div class="mt-4 mb-2 p-2 rounded shadow-sm" style="background: rgba(217, 119, 54, 0.8);">
                                    <i class="bi bi-mortarboard-fill"></i> 
                                    <strong>COURSE: <?= strtoupper($current_course ?: 'GENERAL / UNSET') ?></strong>
                                </div>
                                <div class="glass-panel p-2 table-responsive">
                                    <table class="table mb-0">
                                        <thead class="table-dark">
                                            <tr><th>Student Name</th><th>Subject</th><th style="width: 100px;">P</th><th style="width: 100px;">M</th><th style="width: 100px;">F</th><th>Remarks</th></tr>
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
                                <?php endforeach; 
                                if ($current_course !== null) echo "</tbody></table></div>"; 
                                ?>
                        <div class="mt-3"><button name="update_grades" class="btn btn-success shadow"><i class="bi bi-check-circle"></i> Save All Grades</button></div>
                    </form>
                    <?php
                    }
                }
                // --- FINANCE PAGES ---
                elseif ($page == 'finance_load' && $_SESSION['role'] == 'finance') {
                    echo "<h3>Student Loads</h3>";
                    $stmt = $pdo->query("SELECT e.id as eid, u.firstname, u.lastname, s.subject_code, s.subject_title, s.units, s.sy, s.sem FROM enrollments e JOIN users u ON e.student_id = u.id JOIN subjects s ON e.subject_id = s.id ORDER BY u.lastname ASC, s.sy DESC");
                    $loads = $stmt->fetchAll();
                    if (!$loads) { echo "<div class='alert alert-info text-dark mt-3'>No active student loads found.</div>"; } else {
                        ?>
                        <div class="glass-panel p-2 table-responsive mt-3">
                            <table class="table mb-0">
                                <thead class="table-dark"><tr><th>Student Name</th><th>Code</th><th>Subject Title</th><th>Units</th><th>Term (SY/Sem)</th></tr></thead>
                                <tbody>
                                    <?php foreach($loads as $l): ?>
                                    <tr><td><?= $l['lastname'] ?>, <?= $l['firstname'] ?></td><td><?= $l['subject_code'] ?></td><td><?= $l['subject_title'] ?></td><td><?= $l['units'] ?></td><td><?= $l['sy'] ?> - <?= $l['sem'] ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                    }
                }
                elseif ($page == 'finance_fees' && $_SESSION['role'] == 'finance') {
                    ?>
                    <h3>Manage Fee Schedules</h3>
                    <div class="glass-panel p-4 mb-4">
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="subject_id" value="<?= $edit_sub['id'] ?? '' ?>">
                            <div class="col-md-2"><input name="sy" placeholder="SY" class="form-control" value="<?= $edit_sub['sy'] ?? '' ?>" required></div>
                            <div class="col-md-2"><select name="sem" class="form-select"><option <?= ($edit_sub['sem']??'')=='1st'?'selected':'' ?>>1st</option><option <?= ($edit_sub['sem']??'')=='2nd'?'selected':'' ?>>2nd</option></select></div>
                            <div class="col-md-2"><input name="course" placeholder="Course" class="form-control" value="<?= $edit_sub['course'] ?? '' ?>" required></div>
                            <div class="col-md-2"><input name="code" placeholder="Code" class="form-control" value="<?= $edit_sub['subject_code'] ?? '' ?>" required></div>
                            <div class="col-md-3"><input name="title" placeholder="Title" class="form-control" value="<?= $edit_sub['subject_title'] ?? '' ?>" required></div>
                            <div class="col-md-1"><input name="units" type="number" placeholder="Units" class="form-control" value="<?= $edit_sub['units'] ?? '' ?>" required></div>
                            <div class="col-md-3">
                                <select name="teacher_id" class="form-select custom-dark-select">
                                    <option value="0">Unassigned</option>
                                    <?php 
                                    $techs = $pdo->query("SELECT id, lastname FROM users WHERE role='teacher'")->fetchAll();
                                    foreach($techs as $t) echo "<option value='{$t['id']}' ".($edit_sub['teacher_id']??0 == $t['id']?'selected':'').">{$t['lastname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-7"><input name="schedule" placeholder="Schedule" class="form-control" value="<?= $edit_sub['schedule'] ?? '' ?>"></div>
                            <div class="col-md-2"><button name="save_subject" class="btn btn-orange w-100"><?= $edit_sub ? 'Update' : 'Add' ?></button></div>
                        </form></div>
                    
                    <div class="glass-panel p-2 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Target</th><th>Fee Name</th><th>Type</th><th>Amount</th><th>SY/Sem</th><th>Action</th></tr></thead>
                            <?php
                            $fees = $pdo->query("SELECT f.*, u.lastname as student_name FROM fee_schedules f LEFT JOIN users u ON f.student_id = u.id ORDER BY f.sy DESC, f.student_id ASC")->fetchAll();
                            foreach($fees as $f) {
                                $target = $f['student_name'] ? "<span class='badge bg-info text-dark'>{$f['student_name']}</span>" : "<span class='badge bg-secondary'>Global</span>";
                                echo "<tr><td>$target</td><td>{$f['fee_name']}</td><td>{$f['fee_type']}</td><td>₱".number_format($f['amount'],2)."</td><td>{$f['sy']} - {$f['sem']}</td><td><a href='?page=finance_fees&del_fee={$f['id']}' class='btn btn-sm btn-danger'>Del</a></td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'finance_billing' && $_SESSION['role'] == 'finance') {
                    echo "<h3>Student Payable Fees & Balance</h3>";
                    ?>
                    <div class="glass-panel p-2 table-responsive"><table class='table mb-0'><thead class='table-dark'><tr><th>Student</th><th>Total Assessment</th><th>Paid</th><th>Balance</th></tr></thead>
                    <?php
                    $students = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student'")->fetchAll();
                    foreach($students as $b) {
                        $load_stmt = $pdo->prepare("SELECT SUM(amount) FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                        $load_stmt->execute([$b['id']]);
                        $assessment = $load_stmt->fetchColumn() ?: 0;
                        
                        $pay_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
                        $pay_stmt->execute([$b['id']]);
                        $paid = $pay_stmt->fetchColumn();
                        
                        $balance = $assessment - $paid;
                        echo "<tr><td>{$b['lastname']}, {$b['firstname']}</td><td>₱".number_format($assessment,2) . "</td><td class='text-success'>₱".number_format($paid,2)."</td><td class='fw-bold' style='color:#ff6b6b;'>₱".number_format($balance,2)."</td></tr>";
                    }
                    ?>
                    </table></div>
                    <?php
                }
                // --- DEAN PAGES ---
                elseif ($page == 'dean_courses' && $_SESSION['role'] == 'dean') {
                    $edit_sub = null;
                    if(isset($_GET['edit_id'])){ $s = $pdo->prepare("SELECT * FROM subjects WHERE id=?"); $s->execute([$_GET['edit_id']]); $edit_sub = $s->fetch(); }
                    ?>
                    <h3>Course & Subject Management</h3>
                    <div class="glass-panel p-4 mb-4">
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="subject_id" value="<?= $edit_sub['id'] ?? '' ?>">
                            <div class="col-md-2"><input name="sy" placeholder="SY" class="form-control" value="<?= $edit_sub['sy'] ?? '' ?>" required></div>
                            <div class="col-md-2"><select name="sem" class="form-select"><option <?= ($edit_sub['sem']??'')=='1st'?'selected':'' ?>>1st</option><option <?= ($edit_sub['sem']??'')=='2nd'?'selected':'' ?>>2nd</option></select></div>
                            <div class="col-md-2"><input name="course" placeholder="Course" class="form-control" value="<?= $edit_sub['course'] ?? '' ?>" required></div>
                            <div class="col-md-2"><input name="code" placeholder="Code" class="form-control" value="<?= $edit_sub['subject_code'] ?? '' ?>" required></div>
                            <div class="col-md-3"><input name="title" placeholder="Title" class="form-control" value="<?= $edit_sub['subject_title'] ?? '' ?>" required></div>
                            <div class="col-md-1"><input name="units" type="number" placeholder="Units" class="form-control" value="<?= $edit_sub['units'] ?? '' ?>" required></div>
                            <div class="col-md-3">
                                <select name="teacher_id" class="form-select custom-dark-select">
                                    <option value="0">Unassigned</option>
                                    <?php 
                                    $techs = $pdo->query("SELECT id, lastname FROM users WHERE role='teacher'")->fetchAll();
                                    foreach($techs as $t) echo "<option value='{$t['id']}' ".($edit_sub['teacher_id']??0 == $t['id']?'selected':'').">{$t['lastname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-7"><input name="schedule" placeholder="Schedule" class="form-control" value="<?= $edit_sub['schedule'] ?? '' ?>"></div>
                            <div class="col-md-2"><button name="save_subject" class="btn btn-orange w-100"><?= $edit_sub ? 'Update' : 'Add' ?></button></div>
                        </form></div>
                    <div class="glass-panel p-2 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>SY/Sem</th><th>Course</th><th>Subject</th><th>Units</th><th>Action</th></tr></thead>
                            <?php
                            $subs = $pdo->query("SELECT s.*, u.lastname FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY s.sy DESC, s.course ASC")->fetchAll();
                            foreach($subs as $s) {
                                echo "<tr>
                                        <td>{$s['sy']} - {$s['sem']}</td>
                                        <td>{$s['course']}</td>
                                        <td>{$s['subject_code']} - {$s['subject_title']}</td>
                                        <td>{$s['units']}</td>
                                        <td>
                                            <a href='?page=dean_courses&edit_id={$s['id']}' class='btn btn-sm btn-info text-white px-2 py-1 me-1'>Edit</a>
                                            <a href='?page=dean_courses&del_sub={$s['id']}' class='btn btn-sm btn-danger text-white px-2 py-1' onclick='return confirm(\"Are you sure you want to permanently delete this subject offering?\")'>Delete</a>
                                        </td>
                                      </tr>";
                            }
                            ?>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'dean_registered_students' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Registered Students</h3>";
                    $students = $pdo->query("SELECT firstname, lastname, email, username, status, id FROM users WHERE role = 'student' ORDER BY lastname ASC")->fetchAll();
                    echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th></tr></thead>";
                    foreach($students as $s) echo "<tr><td>{$s['lastname']}, {$s['firstname']}</td><td>{$s['username']}</td><td>{$s['email']}</td><td>".strtoupper($s['status'])."</td></tr>";
                    echo "</table></div>";
                }
                elseif ($page == 'dean_enrollment' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Enrolled Students List</h3>";
                    $enrolled = $pdo->query("SELECT DISTINCT u.firstname, u.lastname, u.email, u.id FROM users u JOIN enrollments e ON u.id = e.student_id WHERE u.role = 'student'")->fetchAll();
                    ?>
                    <div class="glass-panel p-2 mt-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Student ID</th><th>Full Name</th><th>Email Address</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($enrolled as $row): ?>
                                <tr><td>STU-00<?= $row['id'] ?></td><td><?= $row['lastname'] ?>, <?= $row['firstname'] ?></td><td><?= $row['email'] ?></td><td><span class="badge bg-success">Enrolled</span></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                elseif ($page == 'dean_teachers' && $_SESSION['role'] == 'dean') {
                    echo "<h3>Teacher Schedules & Assignments</h3>";
                    $schedules = $pdo->query("SELECT s.*, u.firstname, u.lastname FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY u.lastname ASC")->fetchAll();
                    ?>
                    <div class="glass-panel p-2 mt-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Instructor</th><th>Subject Code</th><th>Subject Title</th><th>Schedule</th></tr></thead>
                            <tbody>
                                <?php foreach($schedules as $sch): ?>
                                <tr><td><?= $sch['lastname'] ? $sch['lastname'].", ".$sch['firstname'] : "<span class='text-danger'>Unassigned</span>" ?></td><td><?= $sch['subject_code'] ?></td><td><?= $sch['subject_title'] ?></td><td><?= $sch['schedule'] ? $sch['schedule'] : 'TBA' ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                // --- STUDENT PAGES ---
                elseif ($page == 'my_subjects' && $_SESSION['role'] == 'student') {
                    echo "<h3>Enrolled Subjects</h3>";
                    $my_subs = $pdo->prepare("SELECT e.id as eid, s.* FROM enrollments e JOIN subjects s ON e.subject_id = s.id WHERE e.student_id = ? ORDER BY s.sy DESC, s.sem DESC");
                    $my_subs->execute([$_SESSION['user_id']]);
                    echo "<div class='glass-panel p-2 mb-4 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>SY/Sem</th><th>Code</th><th>Subject</th><th>Units</th><th>Action</th></tr></thead>";
                    while($r = $my_subs->fetch()) echo "<tr><td>{$r['sy']} - {$r['sem']}</td><td>{$r['subject_code']}</td><td>{$r['subject_title']}</td><td>{$r['units']}</td><td><a href='?page=my_subjects&drop_id={$r['eid']}' class='btn btn-sm btn-danger'>Drop</a></td></tr>";
                    echo "</table></div>";
                    
                    echo "<h3>Available Offerings</h3>";
                    $available = $pdo->query("SELECT * FROM subjects WHERE id NOT IN (SELECT subject_id FROM enrollments WHERE student_id = {$_SESSION['user_id']})")->fetchAll();
                    echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>Term</th><th>Subject</th><th>Action</th></tr></thead>";
                    foreach($available as $a) echo "<tr><td>{$a['sy']} - {$a['sem']}</td><td>{$a['subject_title']}</td><td><a href='?page=my_subjects&enroll_id={$a['id']}' class='btn btn-sm btn-orange'>Add</a></td></tr>";
                    echo "</table></div>";
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
                    
                    if (!$my_grades) { echo "<div class='alert alert-info text-dark mt-3'>No grade records found.</div>"; } else {
                        ?>
                        <div class="glass-panel p-2 mt-3">
                            <div class="table-responsive">
                                <table class="table mb-0">
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
                        </div>
                        <?php
                    }
                }
                elseif ($page == 'my_billing' && $_SESSION['role'] == 'student') {
                    $fees_stmt = $pdo->prepare("SELECT SUM(amount) as total FROM fee_schedules WHERE student_id = ? OR student_id IS NULL");
                    $fees_stmt->execute([$_SESSION['user_id']]);
                    $fees = $fees_stmt->fetch();
                    $total_assessment = $fees['total'] ?? 0;
                    
                    $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as paid FROM payments WHERE student_id = ?");
                    $paid_stmt->execute([$_SESSION['user_id']]);
                    $total_paid = $paid_stmt->fetch()['paid'];
                    
                    $balance = $total_assessment - $total_paid;
                    ?>
                    <h3>Billing & Accounts</h3>
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="glass-panel p-4 text-center">
                                <small class="text-muted">Total Assessment</small>
                                <h4>₱<?= number_format($total_assessment, 2) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="glass-panel p-4 text-center">
                                <small class="text-muted">Remaining Balance</small>
                                <h4 style="color: <?= $balance <= 0 ? '#4cd137' : '#ff6b6b' ?>;">₱<?= number_format(max(0, $balance), 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                elseif ($page == 'my_permit' && $_SESSION['role'] == 'student') {
                    ?>
                    <div class="glass-panel p-5 text-center mx-auto" style="max-width: 600px;">
                        <img src="uploads/<?= $currentUser['photo'] ?? 'default.png' ?>" class="rounded-circle mx-auto mb-3 border" style="width:100px; height:100px; object-fit:cover; border-color:#d97736 !important;">
                        <h2>EXAM PERMIT</h2>
                        <p class="mb-0 fw-bold"><?= $_SESSION['name'] ?></p>
                        <p class="text-muted">STU-00<?= $_SESSION['user_id'] ?></p>
                        <h4 style="color:#4cd137;" class="mt-2">STATUS: CLEARED</h4>
                        <hr style="border-color:rgba(255,255,255,0.2);">
                        <small class="text-muted d-block mb-3">This permit is valid for the current examination period.</small>
                        <button onclick="window.print()" class="btn btn-secondary mt-3 no-print">Print Permit</button>
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
