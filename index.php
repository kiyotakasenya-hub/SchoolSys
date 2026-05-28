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
            course VARCHAR(100) DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS subjects (
            id SERIAL PRIMARY KEY,
            subject_code VARCHAR(20),
            subject_title VARCHAR(150),
            units INT,
            sy VARCHAR(20),
            sem VARCHAR(20),
            course VARCHAR(100),
            teacher_id INT DEFAULT NULL,
            schedule VARCHAR(100) DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS enrollments (
            id SERIAL PRIMARY KEY,
            student_id INT,
            subject_id INT
        );
        CREATE TABLE IF NOT EXISTS grades (
            id SERIAL PRIMARY KEY,
            student_id INT,
            subject_id INT,
            prelim NUMERIC(5,2) DEFAULT 0.00,
            midterm NUMERIC(5,2) DEFAULT 0.00,
            prefinal NUMERIC(5,2) DEFAULT 0.00,
            final_grade NUMERIC(5,2) DEFAULT 0.00,
            remarks VARCHAR(20) DEFAULT 'FAIR'
        );
        CREATE TABLE IF NOT EXISTS attendance (
            id SERIAL PRIMARY KEY,
            student_id INT,
            subject_id INT,
            date DATE DEFAULT CURRENT_DATE,
            status VARCHAR(20) DEFAULT 'Present'
        );
        CREATE TABLE IF NOT EXISTS clearance (
            id SERIAL PRIMARY KEY,
            student_id INT,
            dean_status VARCHAR(20) DEFAULT 'Cleared',
            records_status VARCHAR(20) DEFAULT 'Cleared',
            cashier_status VARCHAR(20) DEFAULT 'Cleared',
            finance_status VARCHAR(20) DEFAULT 'Cleared'
        );
        CREATE TABLE IF NOT EXISTS payment_settings (
            id SERIAL PRIMARY KEY,
            downpayment NUMERIC(10,2) DEFAULT 3500.00,
            prelim NUMERIC(10,2) DEFAULT 3000.00,
            midterm NUMERIC(10,2) DEFAULT 3000.00,
            prefinal NUMERIC(10,2) DEFAULT 3000.00,
            final_term NUMERIC(10,2) DEFAULT 3000.00
        );
        CREATE TABLE IF NOT EXISTS payments (
            id SERIAL PRIMARY KEY,
            student_id INT,
            term VARCHAR(20),
            amount NUMERIC(10,2),
            or_number VARCHAR(50),
            date_paid TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
    
    // Seed system variables if missing
    $checkSettings = $pdo->query("SELECT COUNT(*) FROM payment_settings")->fetchColumn();
    if ($checkSettings == 0) {
        $pdo->exec("INSERT INTO payment_settings (downpayment, prelim, midterm, prefinal, final_term) VALUES (3500.00, 3000.00, 3000.00, 3000.00, 3000.00)");
    }
} catch (PDOException $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// --- 2. AUTHENTICATION & CORE CONTROLLER WORKFLOWS ---
session_start();
$msg = "";
$page = $_GET['page'] ?? 'dashboard';

// GLOBAL VISUAL SYSTRAY SETTINGS
if (isset($_GET['toggle_audio_engine'])) {
    $_SESSION['audio_engine'] = ($_SESSION['audio_engine'] ?? 'on') === 'on' ? 'off' : 'on';
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['user']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['pass'], $user['password'])) {
        if ($user['status'] !== 'approved') {
            $msg = "<div class='alert alert-warning text-dark'>Your account activation is currently pending administrator approval.</div>";
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['fullname'] = $user['firstname'] . ' ' . $user['lastname'];
            $_SESSION['course'] = $user['course'];
            header("Location: ?page=dashboard");
            exit;
        }
    } else {
        $msg = "<div class='alert alert-danger text-dark'>Invalid matrix credentials. Security handshake failed.</div>";
    }
}

if (isset($_POST['register_student'])) {
    $hash = password_hash($_POST['pass'], PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status, course) VALUES (?, ?, ?, ?, 'student', 'pending', ?)");
        $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['user'], $hash, $_POST['course']]);
        
        $new_id = $pdo->query("SELECT id FROM users ORDER BY id DESC LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO clearance (student_id) VALUES (?)")->execute([$new_id]);
        
        $msg = "<div class='alert alert-success text-dark'>Registration requested! Awaiting terminal approval from administration.</div>";
    } catch (PDOException $e) {
        if ($e->getCode() == 23505 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
            $msg = "<div class='alert alert-danger text-dark'>Error: The username '{$_POST['user']}' is already registered in our systems.</div>";
        } else {
            $msg = "<div class='alert alert-danger text-dark'>Database Exception: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

if ($page == 'logout') {
    session_destroy();
    header("Location: ?page=dashboard");
    exit;
}

// TEACHER ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'teacher') {
    // Teacher adding their own subject load freely
    if (isset($_POST['teacher_add_subject'])) {
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_title, units, sy, sem, course, teacher_id, schedule) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['code'], $_POST['title'], $_POST['units'], $_POST['sy'], $_POST['sem'], $_POST['course'], $_SESSION['user_id'], $_POST['schedule']]);
        
        $sub_id = $pdo->query("SELECT id FROM subjects ORDER BY id DESC LIMIT 1")->fetchColumn();

        // Auto-enroll eligible students of this specific course & Universal subjects
        $st = $pdo->prepare("SELECT id FROM users WHERE role='student' AND status='approved' AND (course=? OR ?='Universal Standard Subjects')");
        $st->execute([$_POST['course'], $_POST['course']]);
        foreach($st->fetchAll() as $stu) {
            $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
            $check->execute([$stu['id'], $sub_id]);
            if(!$check->fetch()) {
                $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)")->execute([$stu['id'], $sub_id]);
            }
        }
        $msg = "<div class='alert alert-success text-dark'>Subject successfully added to your load! Eligible students were auto-enrolled.</div>";
    }

    if (isset($_POST['update_grades'])) {
        foreach ($_POST['grades'] as $s_id => $sub_grades) {
            foreach ($sub_grades as $sub_id => $terms) {
                $p = floatval($terms['p']);
                $m = floatval($terms['m']);
                $pf = floatval($terms['pf']);
                $f = ($p + $m + $pf) / 3.0;
                $rem = $f >= 3.0 ? 'PASSED' : 'FAILED';
                
                $check = $pdo->prepare("SELECT id FROM grades WHERE student_id=? AND subject_id=?");
                $check->execute([$s_id, $sub_id]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("UPDATE grades SET prelim=?, midterm=?, prefinal=?, final_grade=?, remarks=? WHERE student_id=? AND subject_id=?");
                    $stmt->execute([$p, $m, $pf, $f, $rem, $s_id, $sub_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, prelim, midterm, prefinal, final_grade, remarks) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$s_id, $sub_id, $p, $m, $pf, $f, $rem]);
                }
            }
        }
        $msg = "<div class='alert alert-success text-dark'>Grades compiled and updated to terminal nodes.</div>";
    }
    if (isset($_POST['mark_attendance'])) {
        $sub_id = $_POST['subject_id'];
        $date = $_POST['att_date'];
        foreach ($_POST['att'] as $s_id => $status) {
            $stmt = $pdo->prepare("INSERT INTO attendance (student_id, subject_id, date, status) VALUES (?,?,?,?)");
            $stmt->execute([$s_id, $sub_id, $date, $status]);
        }
        $msg = "<div class='alert alert-success text-dark'>Attendance metrics stored successfully.</div>";
    }
}

// DEAN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'dean') {
    if (isset($_POST['add_subject'])) {
        // Find a teacher matching this course, or leave NULL
        $t_stmt = $pdo->prepare("SELECT id FROM users WHERE role='teacher' AND course=? LIMIT 1");
        $t_stmt->execute([$_POST['course']]);
        $t_id = $t_stmt->fetchColumn() ?: null;

        $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_title, units, sy, sem, course, teacher_id, schedule) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['code'], $_POST['title'], $_POST['units'], $_POST['sy'], $_POST['sem'], $_POST['course'], $t_id, $_POST['schedule']]);

        $sub_id = $pdo->query("SELECT id FROM subjects ORDER BY id DESC LIMIT 1")->fetchColumn();

        // Auto-enroll eligible students of this specific course & Universal subjects
        $st = $pdo->prepare("SELECT id FROM users WHERE role='student' AND status='approved' AND (course=? OR ?='Universal Standard Subjects')");
        $st->execute([$_POST['course'], $_POST['course']]);
        foreach($st->fetchAll() as $stu) {
            $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
            $check->execute([$stu['id'], $sub_id]);
            if(!$check->fetch()) {
                $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)")->execute([$stu['id'], $sub_id]);
            }
        }
        $msg = "<div class='alert alert-success text-dark'>Subject added successfully! Eligible students were automatically enrolled.</div>";
    }
    if (isset($_GET['del_subject'])) {
        $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([$_GET['del_subject']]);
        $pdo->prepare("DELETE FROM enrollments WHERE subject_id=?")->execute([$_GET['del_subject']]);
        $pdo->prepare("DELETE FROM grades WHERE subject_id=?")->execute([$_GET['del_subject']]);
        $msg = "<div class='alert alert-danger text-dark'>Subject dropped from current academic matrix records.</div>";
    }
    if (isset($_GET['clear_student_dean'])) {
        $pdo->prepare("UPDATE clearance SET dean_status='Cleared' WHERE student_id=?")->execute([$_GET['clear_student_dean']]);
        header("Location: ?page=dean_clearance"); exit;
    }
    if (isset($_GET['unclear_student_dean'])) {
        $pdo->prepare("UPDATE clearance SET dean_status='Hold' WHERE student_id=?")->execute([$_GET['unclear_student_dean']]);
        header("Location: ?page=dean_clearance"); exit;
    }
}

// RECORDS ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'records') {
    if (isset($_GET['clear_student_rec'])) {
        $pdo->prepare("UPDATE clearance SET records_status='Cleared' WHERE student_id=?")->execute([$_GET['clear_student_rec']]);
        header("Location: ?page=records_clearance"); exit;
    }
    if (isset($_GET['unclear_student_rec'])) {
        $pdo->prepare("UPDATE clearance SET records_status='Hold' WHERE student_id=?")->execute([$_GET['unclear_student_rec']]);
        header("Location: ?page=records_clearance"); exit;
    }
}

// CASHIER & FINANCE ACTIONS
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['cashier', 'finance'])) {
    if (isset($_POST['save_settings'])) {
        $pdo->prepare("UPDATE payment_settings SET downpayment=?, prelim=?, midterm=?, prefinal=?, final_term=? WHERE id=1")
            ->execute([$_POST['dp'], $_POST['p'], $_POST['m'], $_POST['pf'], $_POST['f']]);
        $msg = "<div class='alert alert-success text-dark'>Global billing structures adjusted dynamically.</div>";
    }
    if (isset($_POST['post_payment'])) {
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, term, amount, or_number) VALUES (?,?,?,?)");
        $stmt->execute([$_POST['student_id'], $_POST['term'], $_POST['amount'], $_POST['or_number']]);
        $msg = "<div class='alert alert-success text-dark'>Payment posted successfully. Receipts finalized.</div>";
    }
    if (isset($_GET['clear_student_fin'])) {
        $col = $_SESSION['role'] == 'cashier' ? 'cashier_status' : 'finance_status';
        $pdo->prepare("UPDATE clearance SET $col='Cleared' WHERE student_id=?")->execute([$_GET['clear_student_fin']]);
        header("Location: ?page=" . $_SESSION['role'] . "_clearance"); exit;
    }
    if (isset($_GET['unclear_student_fin'])) {
        $col = $_SESSION['role'] == 'cashier' ? 'cashier_status' : 'finance_status';
        $pdo->prepare("UPDATE clearance SET $col='Hold' WHERE student_id=?")->execute([$_GET['unclear_student_fin']]);
        header("Location: ?page=" . $_SESSION['role'] . "_clearance"); exit;
    }
}

// ADMIN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_POST['create_staff'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        try {
            $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status) VALUES (?, ?, ?, ?, ?, 'approved')");
            $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['user'], $hash, $role]);
            $msg = "<div class='alert alert-success text-dark'>Staff account created successfully.</div>";
        } catch (PDOException $e) {
            if ($e->getCode() == 23505 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
                $msg = "<div class='alert alert-danger text-dark'>Error: The username '{$_POST['user']}' is already taken. Please choose a different username.</div>";
            } else {
                $msg = "<div class='alert alert-danger text-dark'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    if (isset($_GET['approve_user'])) {
        $pdo->prepare("UPDATE users SET status='approved' WHERE id=?")->execute([$_GET['approve_user']]);
        header("Location: ?page=manage_users"); exit;
    }
    if (isset($_GET['suspend_user'])) {
        $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$_GET['suspend_user']]);
        header("Location: ?page=manage_users"); exit;
    }
    if (isset($_GET['delete_user'])) {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_GET['delete_user']]);
        $pdo->prepare("DELETE FROM clearance WHERE student_id=?")->execute([$_GET['delete_user']]);
        $pdo->prepare("DELETE FROM enrollments WHERE student_id=?")->execute([$_GET['delete_user']]);
        header("Location: ?page=manage_users"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>⚡ CYBERNET ACADEMIC CORE v4.0 ⚡</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --cyber-bg: #0b0c10;
            --cyber-panel: rgba(26, 26, 36, 0.75);
            --neon-blue: #00f0ff;
            --neon-orange: #ff007f;
            --neon-green: #39ff14;
            --cyber-text: #c5c6c7;
        }
        body {
            background-color: var(--cyber-bg);
            color: var(--cyber-text);
            font-family: 'Courier New', Courier, monospace;
            overflow-x: hidden;
            background-image: linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        #matrix-canvas {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            z-index: -1; opacity: 0.15; pointer-events: none;
        }
        .glass-panel {
            background: var(--cyber-panel);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(0, 240, 255, 0.2);
            border-radius: 4px;
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.1);
            color: #fff;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .glass-panel:hover {
            border-color: var(--neon-blue);
            box-shadow: 0 0 25px rgba(0, 240, 255, 0.3);
        }
        .btn-orange {
            background: transparent;
            border: 1px solid var(--neon-orange);
            color: var(--neon-orange);
            text-shadow: 0 0 5px var(--neon-orange);
            box-shadow: 0 0 5px rgba(255, 0, 127, 0.2);
        }
        .btn-orange:hover {
            background: var(--neon-orange);
            color: #000;
            box-shadow: 0 0 15px var(--neon-orange);
        }
        .sidebar {
            min-height: 100vh;
            background: rgba(11, 12, 16, 0.9);
            border-right: 2px solid var(--neon-blue);
            padding-top: 20px;
        }
        .sidebar a {
            color: var(--cyber-text);
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            border-left: 3px solid transparent;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        .sidebar a:hover, .sidebar a.active {
            background: rgba(0, 240, 255, 0.1);
            color: var(--neon-blue);
            border-left-color: var(--neon-blue);
            text-shadow: 0 0 8px var(--neon-blue);
        }
        .table { color: var(--cyber-text); }
        .table-dark { --bs-table-bg: #1f2833; }
        .form-control, .form-select {
            background: rgba(0,0,0,0.5);
            border: 1px solid rgba(0, 240, 255, 0.3);
            color: #fff;
        }
        .form-control:focus, .form-select:focus {
            background: rgba(0,0,0,0.7);
            color: #fff;
            border-color: var(--neon-blue);
            box-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
        }
        .custom-dark-select option {
            background-color: #1a1a24 !important;
            color: #fff !important;
        }
        /* Audio visualizer styling */
        .visualizer-container {
            border: 1px solid var(--neon-blue);
            background: rgba(0,0,0,0.6);
            padding: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<canvas id="matrix-canvas"></canvas>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar p-0 d-flex flex-column justify-content-between">
            <div>
                <div class="text-center mb-4 px-3">
                    <h4 class="text-white tracking-widest mt-2" style="text-shadow:0 0 10px var(--neon-blue);">CYBERNET CORE</h4>
                    <span class="badge bg-dark text-info border border-info px-2 py-1 mt-1" style="font-size:0.7rem;">v4.0 STABLE</span>
                </div>
                
                <?php if (isset($_SESSION['role'])): ?>
                    <div class="px-3 mb-3 text-white-50 small text-uppercase">Node: <?php echo htmlspecialchars($_SESSION['fullname']); ?> (<?php echo strtoupper($_SESSION['role']); ?>)</div>
                    <a href="?page=dashboard" class="<?php echo $page=='dashboard'?'active':''; ?>">📊 System Dashboard</a>
                    
                    <?php if ($_SESSION['role'] == 'student'): ?>
                        <a href="?page=student_grades" class="<?php echo $page=='student_grades'?'active':''; ?>">📜 Terminal Grades</a>
                        <a href="?page=student_attendance" class="<?php echo $page=='student_attendance'?'active':''; ?>">🕒 Attendance Logs</a>
                        <a href="?page=student_clearance" class="<?php echo $page=='student_clearance'?'active':''; ?>">🔑 Node Clearance</a>
                        <a href="?page=student_billing" class="<?php echo $page=='student_billing'?'active':''; ?>">💳 Mainframe Ledger</a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] == 'teacher'): ?>
                        <a href="?page=teacher_classes" class="<?php echo $page=='teacher_classes'?'active':''; ?>">🏫 My Assigned Classes</a>
                        <a href="?page=teacher_grades" class="<?php echo $page=='teacher_grades'?'active':''; ?>">✍️ Compile Class Grades</a>
                        <a href="?page=teacher_attendance" class="<?php echo $page=='teacher_attendance'?'active':''; ?>">📊 Sync Attendance</a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] == 'dean'): ?>
                        <a href="?page=dean_subjects" class="<?php echo $page=='dean_subjects'?'active':''; ?>">📚 Structure Matrix Loads</a>
                        <a href="?page=dean_clearance" class="<?php echo $page=='dean_clearance'?'active':''; ?>">🛡️ Structural Clearances</a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] == 'records'): ?>
                        <a href="?page=records_clearance" class="<?php echo $page=='records_clearance'?'active':''; ?>">📁 Archive Node Clearance</a>
                        <a href="?page=view_all_grades" class="<?php echo $page=='view_all_grades'?'active':''; ?>">📑 Master Academic Gradebook</a>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['role'], ['cashier', 'finance'])): ?>
                        <a href="?page=billing_settings" class="<?php echo $page=='billing_settings'?'active':''; ?>">⚙️ Configure Tariffs</a>
                        <a href="?page=post_payment" class="<?php echo $page=='post_payment'?'active':''; ?>">💰 Inject Payment Link</a>
                        <a href="?page=<?php echo $_SESSION['role']; ?>_clearance" class="<?php echo $page==$_SESSION['role'].'_clearance'?'active':''; ?>">🪙 Financial Clearance</a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] == 'admin'): ?>
                        <a href="?page=manage_users" class="<?php echo $page=='manage_users'?'active':''; ?>">👥 Admin Node Manager</a>
                        <a href="?page=create_staff" class="<?php echo $page=='create_staff'?'active':''; ?>">⚡ Register Staff Node</a>
                    <?php endif; ?>
                    
                    <a href="?page=logout" class="text-danger mt-4">⚠️ Logoff System</a>
                <?php else: ?>
                    <a href="?page=dashboard" class="<?php echo $page=='dashboard'?'active':''; ?>">🔒 Terminal Authentication</a>
                <?php endif; ?>
            </div>

            <div class="p-3 border-top border-secondary">
                <div class="visualizer-container">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-white-50" style="font-size:0.75rem;">SYNTH AUDIO ENGINE</span>
                        <a href="?toggle_audio_engine=1" class="badge <?php echo ($_SESSION['audio_engine']??'on')==='on'?'bg-success':'bg-danger'; ?> text-decoration-none" style="font-size:0.65rem;">
                            <?php echo ($_SESSION['audio_engine']??'on')==='on'?'ONLINE':'OFFLINE'; ?>
                        </a>
                    </div>
                    <canvas id="audio-visualizer-canvas" style="width:100%; height:40px; background:#000;" class="mb-2"></canvas>
                    <div style="font-size:0.7rem; color:var(--neon-blue);" class="text-truncate" id="ui-current-track">Matrix Stream Off</div>
                    <div class="d-flex justify-content-between mt-1">
                        <button class="btn btn-xs py-0 px-1 btn-outline-info" style="font-size:0.65rem;" onclick="prevTrack()">⏮</button>
                        <button class="btn btn-xs py-0 px-1 btn-outline-info" style="font-size:0.65rem;" onclick="togglePlayPause()">⏯</button>
                        <button class="btn btn-xs py-0 px-1 btn-outline-info" style="font-size:0.65rem;" onclick="nextTrack()">⏭</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-10 p-4" id="spa-content-container">
            <?php echo $msg; ?>

            <?php
            // --- 3. DYNAMIC WORKFLOW ROUTER ENGINE ---
            if (!isset($_SESSION['user_id'])) {
                // ANONYMOUS ACCESS SYSTEM
                if ($page == 'register') {
                    ?>
                    <h3>Initialize Student Matrix Node</h3>
                    <div class="glass-panel p-4" style="max-width: 500px;">
                        <form method="POST" action="?page=register">
                            <input name="fname" placeholder="First Name" class="form-control mb-2" required>
                            <input name="lname" placeholder="Last Name" class="form-control mb-2" required>
                            <input name="user" placeholder="Desired Username Node" class="form-control mb-2" required>
                            <input name="pass" type="password" placeholder="System Security Password" class="form-control mb-2" required>
                            <label class="form-label text-white-50 small mt-2">Select Core Course Pathway Matrix</label>
                            <select name="course" class="form-select custom-dark-select mb-3">
                                <option value="BS Accountancy">BS Accountancy</option>
                                <option value="BS Business Administration">BS Business Administration</option>
                                <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                                <option value="BS Legal Management">BS Legal Management</option>
                                <option value="BS Tourism/Hospitality Management">BS Tourism/Hospitality Management</option>
                                <option value="BS Computer Science">BS Computer Science</option>
                                <option value="BS Information Technology">BS Information Technology</option>
                                <option value="BS Engineering">BS Engineering</option>
                                <option value="BS Architecture">BS Architecture</option>
                                <option value="BS Nursing">BS Nursing</option>
                                <option value="BS Psychology">BS Psychology</option>
                                <option value="AB Communication/Journalism">AB Communication/Journalism</option>
                                <option value="AB Political Science">AB Political Science</option>
                                <option value="BA Fine Arts/Multimedia Arts">BA Fine Arts/Multimedia Arts</option>
                                <option value="Bachelor in Elementary Education">Bachelor in Elementary Education</option>
                                <option value="Bachelor in Secondary Education">Bachelor in Secondary Education</option>
                                <option value="Bachelor of Early Childhood Education">Bachelor of Early Childhood Education</option>
                            </select>
                            <button name="register_student" class="btn btn-orange w-100 mb-2">Initiate System Handshake</button>
                            <a href="?page=dashboard" class="text-info d-block text-center small mt-2">Back to Matrix Credentials Terminal</a>
                        </form>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:70vh;">
                        <div class="glass-panel p-5 login-box" style="width: 100%; max-width: 450px;">
                            <h3 class="text-center mb-4" style="color:var(--neon-blue); text-shadow: 0 0 10px rgba(0,240,255,0.4);">AUTHENTICATION PORTAL</h3>
                            <form method="POST" action="?page=dashboard">
                                <div class="mb-3">
                                    <input name="user" class="form-control text-center" placeholder="USERNAME SECTOR ID" required autocomplete="off">
                                </div>
                                <div class="mb-4">
                                    <input name="pass" type="password" class="form-control text-center" placeholder="SECURITY DECRYPTION ACCESS" required>
                                </div>
                                <button name="login" class="btn btn-orange w-100 py-2 mb-3">AUTHORIZE CONNECTIVITY</button>
                                <div class="text-center">
                                    <a href="?page=register" class="text-info small text-decoration-none">Create a new student account framework &rarr;</a>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php
                }
            } else {
                // AUTHENTICATED SYSTEM HUB NODES
                if ($page == 'dashboard') {
                    ?>
                    <h3>System Dashboard</h3>
                    <p class="text-white-50">Welcome back to the terminal framework, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong>.</p>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="glass-panel p-3">
                                <h5>Active System Core</h5>
                                <div class="display-6 text-info">ONLINE</div>
                                <small class="text-white-50">Operational nodes executing properly.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="glass-panel p-3">
                                <h5>My Authorization Role</h5>
                                <div class="display-6 text-warning"><?php echo strtoupper($_SESSION['role']); ?></div>
                                <small class="text-white-50">System access privileges bound dynamically.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="glass-panel p-3">
                                <h5>Assigned Segment Area</h5>
                                <div class="fs-4 text-truncate text-success"><?php echo htmlspecialchars($_SESSION['course'] ?? 'Global Root'); ?></div>
                                <small class="text-white-50">Current sector deployment.</small>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                
                // STUDENT VIEW INTERFACES
                elseif ($page == 'student_grades' && $_SESSION['role'] == 'student') {
                    ?>
                    <h3>My Academic Ledger Terminal</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Subject Code</th>
                                    <th>Title</th>
                                    <th>Prelim</th>
                                    <th>Midterm</th>
                                    <th>Pre-Final</th>
                                    <th>Final Metric</th>
                                    <th>System Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $pdo->prepare("SELECT s.*, g.prelim, g.midterm, g.prefinal, g.final_grade, g.remarks FROM enrollments e JOIN subjects s ON e.subject_id = s.id LEFT JOIN grades g ON (g.subject_id = s.id AND g.student_id = e.student_id) WHERE e.student_id = ?");
                                $stmt->execute([$_SESSION['user_id']]);
                                $rows = $stmt->fetchAll();
                                if(empty($rows)) echo "<tr><td colspan='7' class='text-center text-white-50'>No curriculum components tracked in your layout yet.</td></tr>";
                                foreach ($rows as $r) {
                                    echo "<tr>
                                        <td>{$r['subject_code']}</td>
                                        <td>{$r['subject_title']}</td>
                                        <td>" . ($r['prelim'] ?? '0.00') . "</td>
                                        <td>" . ($r['midterm'] ?? '0.00') . "</td>
                                        <td>" . ($r['prefinal'] ?? '0.00') . "</td>
                                        <td class='text-info fw-bold'>" . ($r['final_grade'] ?? '0.00') . "</td>
                                        <td><span class='badge " . (($r['remarks']??'') == 'FAILED' ? 'bg-danger':'bg-success') . "'>{$r['remarks']}</span></td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                elseif ($page == 'student_attendance' && $_SESSION['role'] == 'student') {
                    ?>
                    <h3>Attendance Tracking Ledger</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date Matrix</th>
                                    <th>Subject Component</th>
                                    <th>Status Flag</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $pdo->prepare("SELECT a.date, a.status, s.subject_title FROM attendance a JOIN subjects s ON a.subject_id = s.id WHERE a.student_id = ? ORDER BY a.date DESC");
                                $stmt->execute([$_SESSION['user_id']]);
                                $rows = $stmt->fetchAll();
                                if(empty($rows)) echo "<tr><td colspan='3' class='text-center text-white-50'>No recorded attendance vectors available.</td></tr>";
                                foreach ($rows as $r) {
                                    echo "<tr>
                                        <td>{$r['date']}</td>
                                        <td>{$r['subject_title']}</td>
                                        <td><span class='badge " . ($r['status'] == 'Present' ? 'bg-success':'bg-danger') . "'>{$r['status']}</span></td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                elseif ($page == 'student_clearance' && $_SESSION['role'] == 'student') {
                    $c = $pdo->prepare("SELECT * FROM clearance WHERE student_id = ?");
                    $c->execute([$_SESSION['user_id']]);
                    $cl = $c->fetch() ?: ['dean_status'=>'Hold','records_status'=>'Hold','cashier_status'=>'Hold','finance_status'=>'Hold'];
                    ?>
                    <h3>Node Security Clearance Parameters</h3>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="glass-panel p-4 text-center">
                                <h6>Dean Control Sector</h6>
                                <div class="fs-4 fw-bold <?php echo $cl['dean_status']=='Cleared'?'text-success':'text-danger'; ?>"><?php echo $cl['dean_status']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="glass-panel p-4 text-center">
                                <h6>Records Repository</h6>
                                <div class="fs-4 fw-bold <?php echo $cl['records_status']=='Cleared'?'text-success':'text-danger'; ?>"><?php echo $cl['records_status']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="glass-panel p-4 text-center">
                                <h6>Cashier Operational Grid</h6>
                                <div class="fs-4 fw-bold <?php echo $cl['cashier_status']=='Cleared'?'text-success':'text-danger'; ?>"><?php echo $cl['cashier_status']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="glass-panel p-4 text-center">
                                <h6>Finance Mainframe Routing</h6>
                                <div class="fs-4 fw-bold <?php echo $cl['finance_status']=='Cleared'?'text-success':'text-danger'; ?>"><?php echo $cl['finance_status']; ?></div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                
                elseif ($page == 'student_billing' && $_SESSION['role'] == 'student') {
                    $settings = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch();
                    $pmts = $pdo->prepare("SELECT * FROM payments WHERE student_id = ? ORDER BY date_paid DESC");
                    $pmts->execute([$_SESSION['user_id']]);
                    $history = $pmts->fetchAll();
                    
                    $paid_terms = [];
                    foreach($history as $h) { $paid_terms[] = strtolower($h['term']); }
                    ?>
                    <h3>Account Billing Mainframe Status</h3>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="glass-panel p-3">
                                <h5>Structured Balance Layout</h5>
                                <ul class="list-unstyled small mb-0 mt-2">
                                    <li>Downpayment: $<?php echo number_with_fallback($settings['downpayment']); ?> - <?php echo in_array('downpayment', $paid_terms)?'<span class="text-success">PAID</span>':'<span class="text-danger">UNPAID</span>'; ?></li>
                                    <li>Prelim Assessment: $<?php echo number_with_fallback($settings['prelim']); ?> - <?php echo in_array('prelim', $paid_terms)?'<span class="text-success">PAID</span>':'<span class="text-danger">UNPAID</span>'; ?></li>
                                    <li>Midterm Matrix: $<?php echo number_with_fallback($settings['midterm']); ?> - <?php echo in_array('midterm', $paid_terms)?'<span class="text-success">PAID</span>':'<span class="text-danger">UNPAID</span>'; ?></li>
                                    <li>Pre-Final Assessment: $<?php echo number_with_fallback($settings['prefinal']); ?> - <?php echo in_array('prefinal', $paid_terms)?'<span class="text-success">PAID</span>':'<span class="text-danger">UNPAID</span>'; ?></li>
                                    <li>Final Operational Metric: $<?php echo number_with_fallback($settings['final_term']); ?> - <?php echo in_array('final_term', $paid_terms)?'<span class="text-success">PAID</span>':'<span class="text-danger">UNPAID</span>'; ?></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="glass-panel p-3">
                                <h5>Validated Payments Logs</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Term Component</th><th>Amount Settled</th><th>OR Code</th><th>Timestamp Logged</th></tr></thead>
                                        <tbody>
                                            <?php
                                            if(empty($history)) echo "<tr><td colspan='4' class='text-center text-white-50'>No validated payments on record.</td></tr>";
                                            foreach($history as $h) {
                                                echo "<tr><td>" . strtoupper($h['term']) . "</td><td>\${$h['amount']}</td><td>{$h['or_number']}</td><td>{$h['date_paid']}</td></tr>";
                                            }
                                            function number_with_fallback($val) { return number_format(floatval($val), 2); }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                
                // TEACHER VIEW INTERFACES
                elseif ($page == 'teacher_classes' && $_SESSION['role'] == 'teacher') {
                    ?>
                    <h3>My Assigned Classes Matrix</h3>
                    
                    <div class="glass-panel p-4 mb-4">
                        <h5 class="mb-3" style="color:var(--neon-blue);">Freely Add a Subject to Your Workload</h5>
                        <form method="POST" class="row g-2">
                            <div class="col-md-2">
                                <input name="sy" placeholder="SY (e.g. 2024-2025)" class="form-control" value="2024-2025" required>
                            </div>
                            <div class="col-md-2">
                                <select name="sem" class="form-select custom-dark-select">
                                    <option>1st</option>
                                    <option>2nd</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="course" id="teacherCourseSelect" class="form-select custom-dark-select" required onchange="populateTeacherSubjects()">
                                    <option value="" disabled selected>Select Course Category</option>
                                    <option value="Universal Standard Subjects">Universal Standard Subjects</option>
                                    <option value="BS Accountancy">BS Accountancy</option>
                                    <option value="BS Business Administration">BS Business Administration</option>
                                    <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                                    <option value="BS Legal Management">BS Legal Management</option>
                                    <option value="BS Tourism/Hospitality Management">BS Tourism/Hospitality Management</option>
                                    <option value="BS Computer Science">BS Computer Science</option>
                                    <option value="BS Information Technology">BS Information Technology</option>
                                    <option value="BS Engineering">BS Engineering</option>
                                    <option value="BS Architecture">BS Architecture</option>
                                    <option value="BS Nursing">BS Nursing</option>
                                    <option value="BS Psychology">BS Psychology</option>
                                    <option value="AB Communication/Journalism">AB Communication/Journalism</option>
                                    <option value="AB Political Science">AB Political Science</option>
                                    <option value="BA Fine Arts/Multimedia Arts">BA Fine Arts/Multimedia Arts</option>
                                    <option value="Bachelor in Elementary Education">Bachelor in Elementary Education</option>
                                    <option value="Bachelor in Secondary Education">Bachelor in Secondary Education</option>
                                    <option value="Bachelor of Early Childhood Education">Bachelor of Early Childhood Education</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select id="teacherSubjectPreset" class="form-select custom-dark-select" onchange="fillTeacherSubjectDetails()">
                                    <option value="">-- Auto-Fill Subject Data --</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input name="code" id="teacherCode" placeholder="Subject Code" class="form-control" required>
                            </div>
                            <div class="col-md-8">
                                <input name="title" id="teacherTitle" placeholder="Subject Title" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <input name="units" id="teacherUnits" type="number" placeholder="Units" class="form-control" required>
                            </div>
                            <div class="col-md-10">
                                <input name="schedule" placeholder="Schedule Configuration (e.g., MWF 9:00 AM - 10:30 AM)" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <button name="teacher_add_subject" class="btn btn-orange w-100">Add to Load</button>
                            </div>
                        </form>
                    </div>

                    <?php
                    $classes = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ? ORDER BY sy DESC, sem DESC");
                    $classes->execute([$_SESSION['user_id']]);
                    echo "<div class='glass-panel p-2 table-responsive'><table class='table mb-0'><thead class='table-dark'><tr><th>Code</th><th>Title</th><th>Course Context</th><th>Schedule Details</th></tr></thead><tbody>";
                    $classList = $classes->fetchAll();
                    if(empty($classList)) echo "<tr><td colspan='4' class='text-center text-white-50'>You have not claimed or been assigned any subjects yet. Use the system tool above to build your workload matrix.</td></tr>";
                    foreach($classList as $c) {
                        echo "<tr><td>{$c['subject_code']}</td><td>{$c['subject_title']}</td><td>{$c['course']}</td><td>{$c['schedule']}</td></tr>";
                    }
                    echo "</tbody></table></div>";
                }
                
                elseif ($page == 'teacher_grades' && $_SESSION['role'] == 'teacher') {
                    $my_subs = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ?");
                    $my_subs->execute([$_SESSION['user_id']]);
                    $subs = $my_subs->fetchAll();
                    ?>
                    <h3>Compile Student Metrics & Grades</h3>
                    <form method="GET" class="row g-2 mb-3">
                        <input type="hidden" name="page" value="teacher_grades">
                        <div class="col-md-6">
                            <select name="target_subject" class="form-select custom-dark-select" required>
                                <option value="">Select Target Class Module Matrix</option>
                                <?php foreach($subs as $s) echo "<option value='{$s['id']}' ".(($_GET['target_subject']??'')==$s['id']?'selected':'').">{$s['subject_code']} - {$s['subject_title']}</option>"; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn btn-orange w-100">Fetch Node Students</button></div>
                    </form>
                    
                    <?php if (isset($_GET['target_subject']) && !empty($_GET['target_subject'])): ?>
                        <form method="POST">
                            <div class="glass-panel p-3 table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr><th>Student Profile</th><th>Prelim</th><th>Midterm</th><th>Pre-Final</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $st_stmt = $pdo->prepare("SELECT u.id, u.firstname, u.lastname, g.prelim, g.midterm, g.prefinal FROM enrollments e JOIN users u ON e.student_id = u.id LEFT JOIN grades g ON (g.subject_id = e.subject_id AND g.student_id = u.id) WHERE e.subject_id = ? AND u.status='approved'");
                                        $st_stmt->execute([$_GET['target_subject']]);
                                        $students = $st_stmt->fetchAll();
                                        if(empty($students)) echo "<tr><td colspan='4' class='text-center text-white-50'>No active students linked to this classroom context.</td></tr>";
                                        foreach($students as $st) {
                                            echo "<tr>
                                                <td>{$st['firstname']} {$st['lastname']}</td>
                                                <td><input type='number' step='0.01' name='grades[{$st['id']}][{$_GET['target_subject']}][p]' class='form-control form-control-sm' value='{$st['prelim']}' style='width:90px;' min='1.00' max='5.00'></td>
                                                <td><input type='number' step='0.01' name='grades[{$st['id']}][{$_GET['target_subject']}][m]' class='form-control form-control-sm' value='{$st['midterm']}' style='width:90px;' min='1.00' max='5.00'></td>
                                                <td><input type='number' step='0.01' name='grades[{$st['id']}][{$_GET['target_subject']}][pf]' class='form-control form-control-sm' value='{$st['prefinal']}' style='width:90px;' min='1.00' max='5.00'></td>
                                            </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                                <?php if(!empty($students)): ?>
                                    <button name="update_grades" class="btn btn-orange mt-3">Commit Metric Data</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                    <?php
                }
                
                elseif ($page == 'teacher_attendance' && $_SESSION['role'] == 'teacher') {
                    $my_subs = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ?");
                    $my_subs->execute([$_SESSION['user_id']]);
                    $subs = $my_subs->fetchAll();
                    ?>
                    <h3>Sync Attendance Nodes</h3>
                    <form method="GET" class="row g-2 mb-3">
                        <input type="hidden" name="page" value="teacher_attendance">
                        <div class="col-md-6">
                            <select name="target_subject" class="form-select custom-dark-select" required>
                                <option value="">Select Target Class Module Matrix</option>
                                <?php foreach($subs as $s) echo "<option value='{$s['id']}' ".(($_GET['target_subject']??'')==$s['id']?'selected':'').">{$s['subject_code']} - {$s['subject_title']}</option>"; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn btn-orange w-100">Load Matrix Node</button></div>
                    </form>

                    <?php if (isset($_GET['target_subject']) && !empty($_GET['target_subject'])): ?>
                        <form method="POST">
                            <input type="hidden" name="subject_id" value="<?php echo htmlspecialchars($_GET['target_subject']); ?>">
                            <div class="glass-panel p-3">
                                <div class="mb-3" style="max-width:250px;">
                                    <label class="form-label text-white-50 small">Log Date</label>
                                    <input type="date" name="att_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <table class="table mb-0">
                                    <thead class="table-dark"><tr><th>Student Profile</th><th>Status Flag Injection</th></tr></thead>
                                    <tbody>
                                        <?php
                                        $st_stmt = $pdo->prepare("SELECT u.id, u.firstname, u.lastname FROM enrollments e JOIN users u ON e.student_id = u.id WHERE e.subject_id = ? AND u.status='approved'");
                                        $st_stmt->execute([$_GET['target_subject']]);
                                        $students = $st_stmt->fetchAll();
                                        if(empty($students)) echo "<tr><td colspan='2' class='text-center text-white-50'>No registered nodes connected here.</td></tr>";
                                        foreach($students as $st) {
                                            echo "<tr>
                                                <td>{$st['firstname']} {$st['lastname']}</td>
                                                <td>
                                                    <select name='att[{$st['id']}]' class='form-select form-select-sm custom-dark-select' style='width:150px;'>
                                                        <option value='Present'>Present Node</option>
                                                        <option value='Absent'>Absent State</option>
                                                    </select>
                                                </td>
                                            </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                                <?php if(!empty($students)): ?>
                                    <button name="mark_attendance" class="btn btn-orange mt-3">Inject Attendance Log</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                    <?php
                }
                
                // DEAN VIEW INTERFACES
                elseif ($page == 'dean_subjects' && $_SESSION['role'] == 'dean') {
                    ?>
                    <h3>Academic Operations Framework Structure</h3>
                    <div class="glass-panel p-4 mb-4">
                        <h5 class="mb-3">Inject New Classroom Node</h5>
                        <form method="POST" class="row g-2">
                            <div class="col-md-2"><input name="sy" placeholder="SY (e.g. 2024-2025)" class="form-control" value="2024-2025" required></div>
                            <div class="col-md-2">
                                <select name="sem" class="form-select custom-dark-select">
                                    <option>1st</option><option>2nd</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="course" id="courseSelect" class="form-select custom-dark-select" required onchange="populateSubjects()">
                                    <option value="" disabled selected>Select Course Stream Matrix</option>
                                    <option value="Universal Standard Subjects">Universal Standard Subjects</option>
                                    <option value="BS Accountancy">BS Accountancy</option>
                                    <option value="BS Business Administration">BS Business Administration</option>
                                    <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                                    <option value="BS Legal Management">BS Legal Management</option>
                                    <option value="BS Tourism/Hospitality Management">BS Tourism/Hospitality Management</option>
                                    <option value="BS Computer Science">BS Computer Science</option>
                                    <option value="BS Information Technology">BS Information Technology</option>
                                    <option value="BS Engineering">BS Engineering</option>
                                    <option value="BS Architecture">BS Architecture</option>
                                    <option value="BS Nursing">BS Nursing</option>
                                    <option value="BS Psychology">BS Psychology</option>
                                    <option value="AB Communication/Journalism">AB Communication/Journalism</option>
                                    <option value="AB Political Science">AB Political Science</option>
                                    <option value="BA Fine Arts/Multimedia Arts">BA Fine Arts/Multimedia Arts</option>
                                    <option value="Bachelor in Elementary Education">Bachelor in Elementary Education</option>
                                    <option value="Bachelor in Secondary Education">Bachelor in Secondary Education</option>
                                    <option value="Bachelor of Early Childhood Education">Bachelor of Early Childhood Education</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select id="subjectPreset" class="form-select custom-dark-select" onchange="fillSubjectDetails()">
                                    <option value="">-- Preset Auto-Fill Architecture --</option>
                                </select>
                            </div>
                            <div class="col-md-2"><input name="code" id="subCode" placeholder="Subject Code" class="form-control" required></div>
                            <div class="col-md-8"><input name="title" id="subTitle" placeholder="Subject Title" class="form-control" required></div>
                            <div class="col-md-2"><input name="units" id="subUnits" type="number" placeholder="Units Value" class="form-control" required></div>
                            <div class="col-md-10"><input name="schedule" placeholder="Schedule Mapping (e.g. TTH 1:00PM - 2:30PM)" class="form-control"></div>
                            <div class="col-md-2"><button name="add_subject" class="btn btn-orange w-100">Provision Load</button></div>
                        </form>
                    </div>

                    <div class="glass-panel p-3 table-responsive">
                        <h5>Master Matrix Load Architecture</h5>
                        <table class="table mb-0">
                            <thead class="table-dark">
                                <tr><th>Code</th><th>Subject Title</th><th>Units</th><th>Target Course</th><th>Assigned Instructor</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $all_subs = $pdo->query("SELECT s.*, u.firstname, u.lastname FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY s.course, s.subject_code");
                                foreach($all_subs->fetchAll() as $s) {
                                    $t_name = $s['firstname'] ? "{$s['firstname']} {$s['lastname']}" : "<span class='text-white-50 small'>Auto-Assign Node Open</span>";
                                    echo "<tr>
                                        <td>{$s['subject_code']}</td>
                                        <td>{$s['subject_title']}</td>
                                        <td>{$s['units']}</td>
                                        <td>{$s['course']}</td>
                                        <td>$t_name</td>
                                        <td><a href='?page=dean_subjects&del_subject={$s['id']}' class='btn btn-xs btn-outline-danger py-0 px-1' style='font-size:0.75rem;'>Wipe</a></td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                elseif ($page == 'dean_clearance' && $_SESSION['role'] == 'dean') {
                    ?>
                    <h3>Structural Clearances - Dean Control Terminal</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Student Node</th><th>Course Path</th><th>Status</th><th>Toggle Action</th></tr></thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT u.id, u.firstname, u.lastname, u.course, c.dean_status FROM users u JOIN clearance c ON u.id=c.student_id WHERE u.role='student' AND u.status='approved'");
                                foreach($st->fetchAll() as $row) {
                                    $link = $row['dean_status']=='Cleared' ? 
                                        "<a href='?page=dean_clearance&unclear_student_dean={$row['id']}' class='btn btn-sm btn-outline-warning py-0 px-1'>Impose Lock</a>" : 
                                        "<a href='?page=dean_clearance&clear_student_dean={$row['id']}' class='btn btn-sm btn-outline-success py-0 px-1'>Verify Node</a>";
                                    echo "<tr><td>{$row['firstname']} {$row['lastname']}</td><td>{$row['course']}</td><td>{$row['dean_status']}</td><td>$link</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                // RECORDS VIEW INTERFACES
                elseif ($page == 'records_clearance' && $_SESSION['role'] == 'records') {
                    ?>
                    <h3>Archive Registries Security Clearance</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Student Node</th><th>Course Path</th><th>Registry Status</th><th>Actions Mapping</th></tr></thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT u.id, u.firstname, u.lastname, u.course, c.records_status FROM users u JOIN clearance c ON u.id=c.student_id WHERE u.role='student' AND u.status='approved'");
                                foreach($st->fetchAll() as $row) {
                                    $link = $row['records_status']=='Cleared' ? 
                                        "<a href='?page=records_clearance&unclear_student_rec={$row['id']}' class='btn btn-sm btn-outline-warning py-0 px-1'>Restrict Node</a>" : 
                                        "<a href='?page=records_clearance&clear_student_rec={$row['id']}' class='btn btn-sm btn-outline-success py-0 px-1'>Authorize Access</a>";
                                    echo "<tr><td>{$row['firstname']} {$row['lastname']}</td><td>{$row['course']}</td><td>{$row['records_status']}</td><td>$link</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                elseif ($page == 'view_all_grades' && $_SESSION['role'] == 'records') {
                    ?>
                    <h3>Master Academic Matrix Gradebook</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-dark"><tr><th>Student Identity</th><th>Course Node</th><th>Subject Code</th><th>Title</th><th>Final Aggregate</th></tr></thead>
                            <tbody>
                                <?php
                                $all = $pdo->query("SELECT u.firstname, u.lastname, u.course, s.subject_code, s.subject_title, g.final_grade FROM grades g JOIN users u ON g.student_id=u.id JOIN subjects s ON g.subject_id=s.id ORDER BY u.lastname, s.subject_code");
                                foreach($all->fetchAll() as $r) {
                                    echo "<tr><td>{$r['lastname']}, {$r['firstname']}</td><td>{$r['course']}</td><td>{$r['subject_code']}</td><td>{$r['subject_title']}</td><td class='text-info fw-bold'>{$r['final_grade']}</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                // CASHIER / FINANCE VIEW INTERFACES
                elseif ($page == 'billing_settings' && in_array($_SESSION['role'], ['cashier', 'finance'])) {
                    $settings = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch();
                    ?>
                    <h3>Configure Global Billing Tariffs</h3>
                    <div class="glass-panel p-4" style="max-width: 500px;">
                        <form method="POST">
                            <div class="mb-2"><label class="small text-white-50">Downpayment Required</label><input name="dp" type="number" step="0.01" class="form-control" value="<?php echo $settings['downpayment']; ?>"></div>
                            <div class="mb-2"><label class="small text-white-50">Prelim Cycle Matrix Cost</label><input name="p" type="number" step="0.01" class="form-control" value="<?php echo $settings['prelim']; ?>"></div>
                            <div class="mb-2"><label class="small text-white-50">Midterm Cycle Cost</label><input name="m" type="number" step="0.01" class="form-control" value="<?php echo $settings['midterm']; ?>"></div>
                            <div class="mb-2"><label class="small text-white-50">Pre-Final Cycle Cost</label><input name="pf" type="number" step="0.01" class="form-control" value="<?php echo $settings['prefinal']; ?>"></div>
                            <div class="mb-3"><label class="small text-white-50">Final Matrix Execution Cost</label><input name="f" type="number" step="0.01" class="form-control" value="<?php echo $settings['final_term']; ?>"></div>
                            <button name="save_settings" class="btn btn-orange w-100">Commit Billing Grid Adjustment</button>
                        </form>
                    </div>
                    <?php
                }
                
                elseif ($page == 'post_payment' && in_array($_SESSION['role'], ['cashier', 'finance'])) {
                    ?>
                    <h3>Inject Payments Link Ledger</h3>
                    <div class="glass-panel p-4" style="max-width: 500px;">
                        <form method="POST">
                            <label class="form-label small text-white-50">Target Student Entity Node</label>
                            <select name="student_id" class="form-select custom-dark-select mb-2" required>
                                <?php
                                $studs = $pdo->query("SELECT id, firstname, lastname FROM users WHERE role='student' AND status='approved'");
                                foreach($studs->fetchAll() as $s) echo "<option value='{$s['id']}'>{$s['firstname']} {$s['lastname']}</option>";
                                ?>
                            </select>
                            <label class="form-label small text-white-50">Target Financial Phase Cycle</label>
                            <select name="term" class="form-select custom-dark-select mb-2">
                                <option value="downpayment">Downpayment Phase</option>
                                <option value="prelim">Prelim Cycle</option>
                                <option value="midterm">Midterm Matrix</option>
                                <option value="prefinal">Pre-Final Cycle</option>
                                <option value="final_term">Final Framework Term</option>
                            </select>
                            <input name="amount" type="number" step="0.01" placeholder="Exact Transferred Amount" class="form-control mb-2" required>
                            <input name="or_number" placeholder="Official Transaction Token / OR Number" class="form-control mb-3" required>
                            <button name="post_payment" class="btn btn-orange w-100">Validate & Commit Injector Link</button>
                        </form>
                    </div>
                    <?php
                }
                
                elseif (in_array($page, ['cashier_clearance', 'finance_clearance']) && in_array($_SESSION['role'], ['cashier', 'finance'])) {
                    $col = $_SESSION['role'] == 'cashier' ? 'cashier_status' : 'finance_status';
                    ?>
                    <h3>Financial Framework Verification System Clearance</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table mb-0">
                            <thead class="table-dark"><tr><th>Student Node</th><th>Assessment Profile Column</th><th>Status Key</th><th>Action Node Inversion</th></tr></thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT u.id, u.firstname, u.lastname, c.$col as status FROM users u JOIN clearance c ON u.id=c.student_id WHERE u.role='student' AND u.status='approved'");
                                foreach($st->fetchAll() as $row) {
                                    $link = $row['status']=='Cleared' ? 
                                        "<a href='?page={$page}&unclear_student_fin={$row['id']}' class='btn btn-sm btn-outline-warning py-0 px-1'>Lock Asset</a>" : 
                                        "<a href='?page={$page}&clear_student_fin={$row['id']}' class='btn btn-sm btn-outline-success py-0 px-1'>Clear Node Balance</a>";
                                    echo "<tr><td>{$row['firstname']} {$row['lastname']}</td><td>Global Ledger Balance Mapping</td><td>{$row['status']}</td><td>$link</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                // ADMIN VIEW INTERFACES
                elseif ($page == 'manage_users' && $_SESSION['role'] == 'admin') {
                    ?>
                    <h3>Admin Node Infrastructure Management</h3>
                    <div class="glass-panel p-3 table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-dark"><tr><th>Account Metadata</th><th>System Role Permission</th><th>Network Status</th><th>Runtime Operations</th></tr></thead>
                            <tbody>
                                <?php
                                $all = $pdo->query("SELECT * FROM users WHERE id != {$_SESSION['user_id']} ORDER BY role, status");
                                foreach($all->fetchAll() as $u) {
                                    $actions = "";
                                    if($u['status'] != 'approved') {
                                        $actions .= "<a href='?page=manage_users&approve_user={$u['id']}' class='btn btn-xs btn-success py-0 px-1 me-1' style='font-size:0.7rem;'>Approve Node</a>";
                                    } else {
                                        $actions .= "<a href='?page=manage_users&suspend_user={$u['id']}' class='btn btn-xs btn-warning py-0 px-1 me-1' style='font-size:0.7rem;'>Suspend Node</a>";
                                    }
                                    $actions .= "<a href='?page=manage_users&delete_user={$u['id']}' class='btn btn-xs btn-danger py-0 px-1' style='font-size:0.7rem;' onclick='return confirm(\"Wipe core metrics?\")'>Purge Node</a>";
                                    
                                    echo "<tr>
                                        <td><strong>{$u['firstname']} {$u['lastname']}</strong><br><small class='text-white-50'>@{$u['username']} - Context: " . ($u['course'] ?? 'Global Root Domain') . "</small></td>
                                        <td><span class='badge bg-secondary'>" . strtoupper($u['role']) . "</span></td>
                                        <td><span class='badge " . ($u['status']=='approved'?'bg-info':'bg-dark text-danger') . "'>{$u['status']}</span></td>
                                        <td>$actions</td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                
                elseif ($page == 'create_staff' && $_SESSION['role'] == 'admin') {
                    echo "<h3>Create Staff Account Frame</h3>";
                    ?>
                    <div class="glass-panel p-4" style="max-width: 500px;">
                        <form method="POST">
                            <input name="fname" placeholder="First Name" class="form-control mb-2" required>
                            <input name="text" style="display:none;"> <input name="lname" placeholder="Last Name" class="form-control mb-2" required>
                            <input name="user" placeholder="Target System Sector Account Username" class="form-control mb-2" required autocomplete="off">
                            <input name="password" type="password" placeholder="System Token Access Password" class="form-control mb-2" required>
                            
                            <select name="role" class="form-select mb-3 custom-dark-select">
                                <option value="teacher">Teacher</option>
                                <option value="dean">Dean</option>
                                <option value="records">Records</option>
                                <option value="cashier">Cashier</option>
                                <option value="finance">Finance</option>
                            </select>

                            <button name="create_staff" class="btn btn-orange w-100">Register Staff Security Credentials</button>
                        </form>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- 4. INTEGRATED CURRICULUM ARCHITECTURE SYSTEM DATA MAPS ---
const curriculumData = {
    "BS Computer Science": [
        {c:"CS101", t:"Introduction to Computing Matrix Data Structures", u:3},
        {c:"CS102", t:"Advanced Object-Oriented Polymorphism Logic", u:3},
        {c:"CS201", t:"Discrete Mathematical Structures & Graph Algorithm Mechanics", u:4},
        {c:"CS302", t:"Automata Framework, Compiler Layouts & Formal Language Systems", u:3}
    ],
    "BS Information Technology": [
        {c:"IT101", t:"Foundations of System Infrastructure & Cyber Architecture", u:3},
        {c:"IT102", t:"Database Management Schemas & Structured Relational Querying", u:3},
        {c:"IT204", t:"Network Interoperability Vectors & Network Switching Frameworks", u:3},
        {c:"IT305", t:"Enterprise Cyber-Security Operations, Defense Shields & Audit Layouts", u:3}
    ],
    "Universal Standard Subjects": [
        {c:"GEN101", t:"Purposive Communication Methods in Digital Sectors", u:3},
        {c:"GEN102", t:"Ethics and Moral Codes in Cybernetic Infrastructures", u:3},
        {c:"GEN203", t:"Contemporary Globalized Grid Networks & Human Interface Elements", u:3},
        {c:"GEN204", t:"Physical Education Node Core - Biometric Training Modules", u:2}
    ],
    "BS Accountancy": [
        {c:"ACC101", t:"Financial Accounting Fundamentals", u:3},
        {c:"ACC202", t:"Cost Accounting & Management Analytics", u:3},
        {c:"ACC305", t:"Auditing Assurance Principles", u:4}
    ],
    "BS Business Administration": [
        {c:"BA101", t:"Principles of Strategic Enterprise Management", u:3},
        {c:"BA202", t:"Human Behavior Dynamics in Operational Sectors", u:3}
    ],
    "BS Engineering": [
        {c:"ENG101", t:"Advanced Differential Calculus Frameworks", u:4},
        {c:"ENG204", t:"Fluid Dynamics Mechanics & Matrix Kinematics", u:3}
    ]
};

// Auto-fill configuration logic handlers for Dean Page View Interface
function populateSubjects() {
    const course = document.getElementById('courseSelect').value;
    const presetSelect = document.getElementById('subjectPreset');
    if(!presetSelect) return;
    
    presetSelect.innerHTML = '<option value="">-- Preset Auto-Fill Architecture --</option>';
    if(curriculumData[course]) {
        curriculumData[course].forEach((subj, index) => {
            const opt = document.createElement('option');
            opt.value = index;
            opt.text = subj.c + " - " + subj.t;
            presetSelect.appendChild(opt);
        });
    }
}
function fillSubjectDetails() {
    const course = document.getElementById('courseSelect').value;
    const presetIdx = document.getElementById('subjectPreset').value;
    if(course && presetIdx !== "" && curriculumData[course] && curriculumData[course][presetIdx]) {
        const data = curriculumData[course][presetIdx];
        document.getElementById('subCode').value = data.c;
        document.getElementById('subTitle').value = data.t;
        document.getElementById('subUnits').value = data.u;
    }
}

// Auto-fill configuration logic handlers for Teacher Page View Interface
window.populateTeacherSubjects = function() {
    const course = document.getElementById('teacherCourseSelect').value;
    const presetSelect = document.getElementById('teacherSubjectPreset');
    if(!presetSelect) return;

    presetSelect.innerHTML = '<option value="">-- Auto-Fill Subject Data --</option>';
    if(curriculumData[course]) {
        curriculumData[course].forEach((subj, index) => {
            const opt = document.createElement('option');
            opt.value = index;
            opt.text = subj.c + " - " + subj.t;
            presetSelect.appendChild(opt);
        });
    }
};
window.fillTeacherSubjectDetails = function() {
    const course = document.getElementById('teacherCourseSelect').value;
    const presetIdx = document.getElementById('teacherSubjectPreset').value;

    if(course && presetIdx !== "" && curriculumData[course] && curriculumData[course][presetIdx]) {
        const data = curriculumData[course][presetIdx];
        document.getElementById('teacherCode').value = data.c;
        document.getElementById('teacherTitle').value = data.t;
        document.getElementById('teacherUnits').value = data.u;
    }
};

// --- 5. CORE AUDIO SYSTEM MATRIX INTERFACE & BACKGROUND GRID STREAMING ---
let audioCtx = null, audioBufferList = [], currentTrackIndex = 0, sourceNode = null, gainNode = null, analyserNode = null;
let isAudioPlaying = false, audioPlaybackPositions = {};

const musicDatabaseTracks = [
    { name: "Neon Grid Pulse Stream [Synthwave Extended]", duration: 180 },
    { name: "Cybernet Core Processing Terminal Ambience", duration: 240 },
    { name: "Vector Pipeline Memory Dump - Sector 7 Data Alpha", duration: 150 }
];

function initMusicDatabase(callback) {
    if (typeof(Storage) !== "undefined" && localStorage.getItem("audio_matrix_db")) {
        audioBufferList = JSON.parse(localStorage.getItem("audio_matrix_db"));
        if(callback) callback();
    } else {
        audioBufferList = musicDatabaseTracks.map((t, idx) => {
            let chData = [];
            for(let i=0; i<44100 * 10; i++) { chData.push(Math.sin(i / (20 + (idx * 5)) + Math.sin(i/1000))); }
            return { id: idx, name: t.name, duration: t.duration, dataRaw: chData };
        });
        try { localStorage.setItem("audio_matrix_db", JSON.stringify(audioBufferList)); } catch(e){}
        if(callback) callback();
    }
}

function restoreTracksFromDB() {
    const isEngineActive = "<?php echo ($_SESSION['audio_engine'] ?? 'on'); ?>";
    if (isEngineActive !== 'on') return;
    setupAudioPipelineContext();
    loadTrackIndexBuffer(currentTrackIndex, false);
}

function setupAudioPipelineContext() {
    if (audioCtx) return;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;
    audioCtx = new AudioContextClass();
    gainNode = audioCtx.createGain();
    analyserNode = audioCtx.createAnalyser();
    analyserNode.fftSize = 64;
    gainNode.connect(analyserNode);
    analyserNode.connect(audioCtx.destination);
    gainNode.gain.setValueAtTime(0.35, audioCtx.currentTime);
    renderVisualizerFrameLoop();
}

function loadTrackIndexBuffer(index, autoPlay = true) {
    if (!audioCtx) setupAudioPipelineContext();
    if (sourceNode) { try { sourceNode.stop(); } catch(e){} sourceNode.disconnect(); }
    
    if (audioCtx.state === 'suspended') {
        document.addEventListener('click', () => { audioCtx.resume(); }, { once: true });
    }

    const currentTrack = audioBufferList[index];
    if (!currentTrack) return;
    
    document.getElementById('ui-current-track').innerText = "STREAM: " + currentTrack.name;
    
    const sampleLength = 44100 * 10;
    let bfr = audioCtx.createBuffer(1, sampleLength, 44100);
    let channelDataArray = bfr.getChannelData(0);
    let rawAudioArray = currentTrack.dataRaw || [];
    for (let i = 0; i < sampleLength; i++) {
        channelDataArray[i] = rawAudioArray[i] ? parseFloat(rawAudioArray[i]) : 0;
    }
    
    sourceNode = audioCtx.createBufferSource();
    sourceNode.buffer = bfr;
    sourceNode.loop = true;
    sourceNode.connect(gainNode);
    
    if (autoPlay) {
        sourceNode.start(0);
        isAudioPlaying = true;
    }
}

function togglePlayPause() {
    if (!audioCtx) setupAudioPipelineContext();
    if (isAudioPlaying) {
        if(sourceNode) { try{ sourceNode.stop(); }catch(e){} }
        isAudioPlaying = false;
        document.getElementById('ui-current-track').innerText = "Stream Paused";
    } else {
        loadTrackIndexBuffer(currentTrackIndex, true);
    }
}

function nextTrack() {
    currentTrackIndex = (currentTrackIndex + 1) % audioBufferList.length;
    loadTrackIndexBuffer(currentTrackIndex, true);
}

function prevTrack() {
    currentTrackIndex = (currentTrackIndex - 1 + audioBufferList.length) % audioBufferList.length;
    loadTrackIndexBuffer(currentTrackIndex, true);
}

function syncPlayerUI() {
    const trackUiElement = document.getElementById('ui-current-track');
    if (trackUiElement && audioBufferList[currentTrackIndex]) {
        trackUiElement.innerText = isAudioPlaying ? "STREAM: " + audioBufferList[currentTrackIndex].name : "Stream Suspended";
    }
}

function renderVisualizerFrameLoop() {
    requestAnimationFrame(renderVisualizerFrameLoop);
    const canvasElement = document.getElementById('audio-visualizer-canvas');
    if (!canvasElement) return;
    const canvasCtx = canvasElement.getContext('2d');
    if (!canvasCtx) return;
    
    const w = canvasElement.width;
    const h = canvasElement.height;
    canvasCtx.clearRect(0, 0, w, h);
    
    if (!analyserNode || !isAudioPlaying) {
        canvasCtx.fillStyle = '#ff007f';
        canvasCtx.fillRect(0, h/2 - 1, w, 2);
        return;
    }
    
    let frequencyDataBuffer = new Uint8Array(analyserNode.frequencyBinCount);
    analyserNode.getByteFrequencyData(frequencyDataBuffer);
    
    const count = frequencyDataBuffer.length;
    const itemWidth = w / count;
    for (let i = 0; i < count; i++) {
        let value = frequencyDataBuffer[i];
        let heightValue = (value / 255) * h;
        canvasCtx.fillStyle = 'rgba(0, 240, 255, ' + (value/255 + 0.2) + ')';
        canvasCtx.fillRect(i * itemWidth, h - heightValue, itemWidth - 1, heightValue);
    }
}

// --- 6. BACKGROUND MATRIX RENDERING SUBSYSTEM ---
const matrixCanvasElement = document.getElementById('matrix-canvas');
if (matrixCanvasElement) {
    const matrixCtx = matrixCanvasElement.getContext('2d');
    let screenWidth = matrixCanvasElement.width = window.innerWidth;
    let screenHeight = matrixCanvasElement.height = window.innerHeight;
    const matrixSymbols = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ☣⚡💻🤖🔒🔑".split("");
    const fontSizeVal = 14;
    let gridColumnsCount = screenWidth / fontSizeVal;
    let dropPositions = [];
    for (let x = 0; x < gridColumnsCount; x++) dropPositions[x] = 1;

    function renderMatrixFrame() {
        matrixCtx.fillStyle = 'rgba(11, 12, 16, 0.05)';
        matrixCtx.fillRect(0, 0, screenWidth, screenHeight);
        matrixCtx.fillStyle = '#00f0ff';
        matrixCtx.font = fontSizeVal + 'px monospace';
        for (let i = 0; i < dropPositions.length; i++) {
            const charText = matrixSymbols[Math.floor(Math.random() * matrixSymbols.length)];
            matrixCtx.fillText(charText, i * fontSizeVal, dropPositions[i] * fontSizeVal);
            if (dropPositions[i] * fontSizeVal > screenHeight && Math.random() > 0.975) dropPositions[i] = 0;
            dropPositions[i]++;
        }
    }
    setInterval(renderMatrixFrame, 35);
    window.addEventListener('resize', () => {
        screenWidth = matrixCanvasElement.width = window.innerWidth;
        screenHeight = matrixCanvasElement.height = window.innerHeight;
        gridColumnsCount = screenWidth / fontSizeVal;
        dropPositions = [];
        for (let x = 0; x < gridColumnsCount; x++) dropPositions[x] = 1;
    });
}

// --- 7. AJAX SPA ROUTING HANDLER ARCHITECTURE ---
function bindSpaFormSubmissions() {
    const links = document.querySelectorAll('.sidebar a, .login-box a, .glass-panel a:not([href*="del_subject"]):not([href*="_student_"])');
    links.forEach(link => {
        if(link.getAttribute('href') && link.getAttribute('href').startsWith('?')) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const urlTarget = this.getAttribute('href');
                history.pushState(null, '', urlTarget);
                
                fetch(urlTarget)
                .then(res => res.text())
                .then(html => {
                    const parserInstance = new DOMParser();
                    const parsedDoc = parserInstance.parseFromString(html, 'text/html');
                    
                    const oldContainer = document.getElementById('spa-content-container');
                    const newContainer = parsedDoc.getElementById('spa-content-container');
                    if(oldContainer && newContainer) {
                        oldContainer.innerHTML = newContainer.innerHTML;
                    }
                    
                    const oldSidebar = document.querySelector('.sidebar');
                    const newSidebar = parsedDoc.querySelector('.sidebar');
                    if(oldSidebar && newSidebar) {
                        // Keep player controls alive intact
                        const currentVisualizer = oldSidebar.querySelector('.visualizer-container');
                        const targetVisualizer = newSidebar.querySelector('.visualizer-container');
                        if (currentVisualizer && targetVisualizer) {
                            targetVisualizer.replaceWith(currentVisualizer.cloneNode(true));
                        }
                        oldSidebar.innerHTML = newSidebar.innerHTML;
                    }
                    
                    // Rebind view scripts
                    bindSpaFormSubmissions();
                    syncPlayerUI();
                });
            });
        }
    });

    const standardForms = document.querySelectorAll('form:not([action*="logout"])');
    standardForms.forEach(f => {
        f.addEventListener('submit', function(e) {
            e.preventDefault();
            const actionUrl = this.getAttribute('action') || window.location.search || '?page=dashboard';
            const postFormData = new FormData(this);
            
            // Re-inject submit button name value pair
            const clickedSubmitButton = e.submitter;
            if(clickedSubmitButton && clickedSubmitButton.getAttribute('name')) {
                postFormData.append(clickedSubmitButton.getAttribute('name'), clickedSubmitButton.value || '');
            }

            fetch(actionUrl, { method: 'POST', body: postFormData })
            .then(res => res.text())
            .then(html => {
                const parserInstance = new DOMParser();
                const foreignDoc = parserInstance.parseFromString(html, 'text/html');
                
                if (window.location.search.includes('page=dashboard') || window.location.search === '') {
                    window.location.reload(); // Hard fallback for clean state switches
                    return;
                }

                const activeContentPanel = document.getElementById('spa-content-container');
                const foreignContentPanel = foreignDoc.getElementById('spa-content-container');
                if (activeContentPanel && foreignContentPanel) {
                    activeContentPanel.innerHTML = foreignContentPanel.innerHTML;
                } else {
                    const activeLogin = document.querySelector('.login-box')?.parentElement;
                    const foreignLogin = foreignDoc.querySelector('.login-box')?.parentElement;
                    if (activeLogin && foreignLogin) {
                        activeLogin.innerHTML = foreignLogin.innerHTML;
                    }
                }
                
                // Clear active modal backdrops manually safely
                const remainingBackdrops = document.querySelectorAll('.modal-backdrop');
                remainingBackdrops.forEach(b => b.remove());
                document.body.classList.remove('modal-open');
                document.body.style = '';
                
                bindSpaFormSubmissions();
                syncPlayerUI();
            })
            .catch(err => { f.submit(); });
        });
    });
}

// System Boot Initialization Execution Sequence
document.addEventListener('DOMContentLoaded', () => {
    initMusicDatabase(restoreTracksFromDB);
    bindSpaFormSubmissions();
});
</script>
</body>
</html>
