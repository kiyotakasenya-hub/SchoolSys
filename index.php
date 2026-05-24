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
            pay_date TIMESTAMP DEFAULT song_timeSTAMP,
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
    created_at TIMESTAMP DEFAULT song_timeSTAMP
);

CREATE TABLE IF NOT EXISTS music_sync (
    id SERIAL PRIMARY KEY,
    song_url TEXT,
    song_time FLOAT DEFAULT 0,
    is_playing BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
    ");

    // DYNAMIC AUTO-PATCHER: Forces missing columns into existing tables without deleting data
    try { $pdo->exec("ALTER TABLE users ADD COLUMN course VARCHAR(150)"); } catch (PDOException $e) { }

	// Create default music sync row
try {

    $checkMusic = $pdo->query("SELECT id FROM music_sync WHERE id = 1");

    if (!$checkMusic->fetch()) {

        $pdo->exec("
            INSERT INTO music_sync
            (id, song_url, song_time, is_playing)
            VALUES
            (1, '', 0, FALSE)
        ");
    }

} catch (PDOException $e) { }

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

// ===============================
// MUSIC SYNC API
// ===============================

if (isset($_POST['sync_music'])) {

    $song = $_POST['song'] ?? '';
    $time = $_POST['time'] ?? 0;
    $playing = ($_POST['playing'] ?? 0) ? 'true' : 'false';

    $stmt = $pdo->prepare("
        UPDATE music_sync
        SET song_url = ?,
            song_time = ?,
            is_playing = $playing,
            updated_at = song_timeSTAMP
        WHERE id = 1
    ");

    $stmt->execute([$song, $time]);

    exit('synced');
}

if (isset($_GET['get_music'])) {

    $stmt = $pdo->query("SELECT * FROM music_sync WHERE id = 1");

    $music = $stmt->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');

    echo json_encode($music);

    exit();
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
    // Use LOWER() to ignore uppercase/lowercase, and LIMIT 1 to safely bypass duplicates
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1"); 
    $stmt->execute([trim($_POST['username'])]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($_POST['password'], $user['password'])) {
        if ($user['status'] == 'approved') {
            $_SESSION['user_id'] = $user['id']; 
            $_SESSION['role'] = $user['role']; 
            $_SESSION['name'] = $user['firstname']." ".$user['lastname'];
            header("Location: " . $_SERVER['PHP_SELF']); 
            exit();
        } else { 
            $msg = "<div class='alert alert-warning text-dark'>Your account is currently: " . strtoupper($user['status']) . "</div>"; 
        }
    } else { 
        $msg = "<div class='alert alert-danger text-dark'>Invalid credentials.</div>"; 
    }
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
            $msg = "<div class='alert alert-success text-dark'>Subject data updated.</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_title, units, sy, sem, course, teacher_id, schedule) VALUES (?,?,?,?,?,?,?,?) RETURNING id");
            $stmt->execute([$_POST['code'], $_POST['title'], $_POST['units'], $_POST['sy'], $_POST['sem'], $_POST['course'], $_POST['teacher_id'], $_POST['schedule']]);
            $new_sub_id = $stmt->fetchColumn(); 

            // Auto-enroll active students of this specific course & Universal subjects
            $st = $pdo->prepare("SELECT id FROM users WHERE role='student' AND status='approved' AND (course=? OR ?='Universal Standard Subjects')");
            $st->execute([$_POST['course'], $_POST['course']]);
            foreach($st->fetchAll() as $stu) {
                // Verify they aren't already enrolled
                $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
                $check->execute([$stu['id'], $new_sub_id]);
                if(!$check->fetch()) {
                    $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)")->execute([$stu['id'], $new_sub_id]);
                }
            }
            $msg = "<div class='alert alert-success text-dark'>Subject added! Eligible students were automatically enrolled.</div>";
        }
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

// ADMIN ACTIONS (Including Global Curriculum Engine for Teachers)
$curriculumData = [
    "Universal Standard Subjects" => [
        ["c"=>"GE-MMW", "t"=>"Mathematics in the Modern World", "u"=>3], ["c"=>"GE-PC", "t"=>"Purposive Communication", "u"=>3], ["c"=>"GE-STS", "t"=>"Science, Technology, and Society", "u"=>3], ["c"=>"GE-CW", "t"=>"Contemporary World", "u"=>3], ["c"=>"GE-AA", "t"=>"Art Appreciation", "u"=>3], ["c"=>"GE-UTS", "t"=>"Understanding the Self", "u"=>3], ["c"=>"GE-RPH", "t"=>"Readings in Philippine History", "u"=>3], ["c"=>"GE-ETH", "t"=>"Ethics", "u"=>3], ["c"=>"RIZAL", "t"=>"Life and Works of Rizal", "u"=>3], ["c"=>"NSTP1", "t"=>"National Service Training Program 1", "u"=>3], ["c"=>"NSTP2", "t"=>"National Service Training Program 2", "u"=>3], ["c"=>"PE1", "t"=>"PE 1 (Fitness/Wellness)", "u"=>2], ["c"=>"PE2", "t"=>"PE 2 (Rhythmic Activities)", "u"=>2], ["c"=>"PE3", "t"=>"PE 3 (Individual/Dual Sports)", "u"=>2], ["c"=>"PE4", "t"=>"PE 4 (Team Sports)", "u"=>2]
    ],
    "BS Accountancy" => [
        ["c"=>"CBB1", "t"=>"Information Technology in Business", "u"=>3], ["c"=>"CBB2", "t"=>"Microeconomics", "u"=>3], ["c"=>"CBB3", "t"=>"Business Law (Obligations and Contracts)", "u"=>3], ["c"=>"CBB4", "t"=>"Income Taxation", "u"=>3], ["c"=>"CBB5", "t"=>"Strategic Management", "u"=>3], ["c"=>"CBB6", "t"=>"Good Governance and Social Responsibility", "u"=>3], ["c"=>"CBB7", "t"=>"Total Quality Management", "u"=>3], ["c"=>"CBB8", "t"=>"Human Resource Management", "u"=>3],
        ["c"=>"FAR", "t"=>"Financial Accounting and Reporting", "u"=>3], ["c"=>"CAC", "t"=>"Cost Accounting and Control", "u"=>3], ["c"=>"IA1", "t"=>"Intermediate Accounting 1", "u"=>3], ["c"=>"IA2", "t"=>"Intermediate Accounting 2", "u"=>3], ["c"=>"IA3", "t"=>"Intermediate Accounting 3", "u"=>3], ["c"=>"CFAS", "t"=>"Conceptual Framework and Accounting Standards", "u"=>3], ["c"=>"AFAR1", "t"=>"Advanced Financial Accounting and Reporting 1", "u"=>3], ["c"=>"AFAR2", "t"=>"Advanced Financial Accounting and Reporting 2", "u"=>3], ["c"=>"MAC", "t"=>"Management Accounting", "u"=>3], ["c"=>"FM", "t"=>"Financial Management", "u"=>3], ["c"=>"MAS", "t"=>"Management Advisory Services", "u"=>3], ["c"=>"AAP", "t"=>"Auditing and Assurance Principles", "u"=>3], ["c"=>"AASI", "t"=>"Auditing and Assurance: Specialized Industries", "u"=>3], ["c"=>"ACIS", "t"=>"Audit in a CIS/IT Environment", "u"=>3], ["c"=>"BTAX", "t"=>"Business Tax", "u"=>3], ["c"=>"TBT", "t"=>"Transfer and Business Taxation", "u"=>3], ["c"=>"RFBT", "t"=>"Regulatory Framework for Business Transactions", "u"=>3], ["c"=>"ARM", "t"=>"Accounting Research Methods", "u"=>3], ["c"=>"AINT", "t"=>"Accounting Internship", "u"=>6]
    ],
    "BS Business Administration" => [
        ["c"=>"CBB1", "t"=>"Information Technology in Business", "u"=>3], ["c"=>"CBB2", "t"=>"Microeconomics", "u"=>3], ["c"=>"CBB3", "t"=>"Business Law (Obligations and Contracts)", "u"=>3], ["c"=>"CBB4", "t"=>"Income Taxation", "u"=>3], ["c"=>"CBB5", "t"=>"Strategic Management", "u"=>3], ["c"=>"CBB6", "t"=>"Good Governance and Social Responsibility", "u"=>3], ["c"=>"CBB7", "t"=>"Total Quality Management", "u"=>3], ["c"=>"CBB8", "t"=>"Human Resource Management", "u"=>3],
        ["c"=>"POM", "t"=>"Principles of Marketing", "u"=>3], ["c"=>"MM", "t"=>"Marketing Management", "u"=>3], ["c"=>"OM", "t"=>"Operations Management", "u"=>3], ["c"=>"BRM", "t"=>"Business Research Methods", "u"=>3], ["c"=>"FM", "t"=>"Financial Management", "u"=>3], ["c"=>"PS", "t"=>"Pricing Strategy", "u"=>3], ["c"=>"CB", "t"=>"Consumer Behavior", "u"=>3], ["c"=>"PROS", "t"=>"Professional Salesmanship", "u"=>3], ["c"=>"BSIM", "t"=>"Business Simulation", "u"=>3], ["c"=>"BINT", "t"=>"Practicum/Internship", "u"=>6]
    ],
    "BS Entrepreneurship" => [
        ["c"=>"CBB1", "t"=>"Information Technology in Business", "u"=>3], ["c"=>"CBB2", "t"=>"Microeconomics", "u"=>3], ["c"=>"CBB3", "t"=>"Business Law (Obligations and Contracts)", "u"=>3], ["c"=>"CBB4", "t"=>"Income Taxation", "u"=>3], ["c"=>"CBB5", "t"=>"Strategic Management", "u"=>3], ["c"=>"CBB6", "t"=>"Good Governance and Social Responsibility", "u"=>3], ["c"=>"CBB7", "t"=>"Total Quality Management", "u"=>3], ["c"=>"CBB8", "t"=>"Human Resource Management", "u"=>3],
        ["c"=>"EM", "t"=>"Entrepreneurial Mindset", "u"=>3], ["c"=>"OSE", "t"=>"Opportunity Spotting and Evaluation", "u"=>3], ["c"=>"MRCB", "t"=>"Market Research and Consumer Behavior", "u"=>3], ["c"=>"BPP", "t"=>"Business Plan Preparation", "u"=>3], ["c"=>"PDI", "t"=>"Product Development and Innovation", "u"=>3], ["c"=>"BPI1", "t"=>"Business Plan Implementation 1", "u"=>3], ["c"=>"BPI2", "t"=>"Business Plan Implementation 2", "u"=>3], ["c"=>"VCD", "t"=>"Venture Capital and Development", "u"=>3], ["c"=>"SBM", "t"=>"Small Business Management", "u"=>3], ["c"=>"EMKT", "t"=>"Entrepreneurial Marketing", "u"=>3]
    ],
    "BS Legal Management" => [
        ["c"=>"CBB1", "t"=>"Information Technology in Business", "u"=>3], ["c"=>"CBB2", "t"=>"Microeconomics", "u"=>3], ["c"=>"CBB3", "t"=>"Business Law (Obligations and Contracts)", "u"=>3], ["c"=>"CBB4", "t"=>"Income Taxation", "u"=>3], ["c"=>"CBB5", "t"=>"Strategic Management", "u"=>3], ["c"=>"CBB6", "t"=>"Good Governance and Social Responsibility", "u"=>3], ["c"=>"CBB7", "t"=>"Total Quality Management", "u"=>3], ["c"=>"CBB8", "t"=>"Human Resource Management", "u"=>3],
        ["c"=>"CLAW", "t"=>"Constitutional Law", "u"=>3], ["c"=>"LBO", "t"=>"Law on Business Organizations", "u"=>3], ["c"=>"LLL", "t"=>"Labor Law and Legislation", "u"=>3], ["c"=>"SACT", "t"=>"Sales, Agency, and Credit Transactions", "u"=>3], ["c"=>"NIL", "t"=>"Negotiable Instruments Law", "u"=>3], ["c"=>"ALAW", "t"=>"Administrative Law", "u"=>3], ["c"=>"IPL", "t"=>"Intellectual Property Law", "u"=>3], ["c"=>"SCON", "t"=>"Statutory Construction", "u"=>3], ["c"=>"LRW", "t"=>"Legal Research and Writing", "u"=>3], ["c"=>"TLAW", "t"=>"Taxation Law", "u"=>3]
    ],
    "BS Tourism/Hospitality Management" => [
        ["c"=>"MMPT", "t"=>"Macro/Micro Perspective of Tourism & Hospitality", "u"=>3], ["c"=>"TPG", "t"=>"Tourism Policy and Governance", "u"=>3], ["c"=>"TTO", "t"=>"Tour and Travel Operations", "u"=>3], ["c"=>"GCG", "t"=>"Global Culture and Geography", "u"=>3], ["c"=>"TMGT", "t"=>"Transportation Management", "u"=>3], ["c"=>"FOO", "t"=>"Front Office Operations", "u"=>3], ["c"=>"KE", "t"=>"Kitchen Essentials", "u"=>3], ["c"=>"FBSO", "t"=>"Food & Beverage Service Operations", "u"=>3], ["c"=>"AO", "t"=>"Accommodation Operations", "u"=>3], ["c"=>"BCM", "t"=>"Banquet and Catering Management", "u"=>3], ["c"=>"EVM", "t"=>"Event Management", "u"=>3], ["c"=>"THP", "t"=>"Tourism/Hospitality Practicum", "u"=>6]
    ],
    "BS Computer Science" => [
        ["c"=>"ITC", "t"=>"Introduction to Computing", "u"=>3], ["c"=>"CP1", "t"=>"Computer Programming 1", "u"=>3], ["c"=>"CP2", "t"=>"Computer Programming 2", "u"=>3], ["c"=>"DSA", "t"=>"Data Structures and Algorithms", "u"=>3], ["c"=>"DM", "t"=>"Discrete Mathematics", "u"=>3], ["c"=>"CCS", "t"=>"Calculus for Computer Science", "u"=>3], ["c"=>"LA", "t"=>"Linear Algebra", "u"=>3], ["c"=>"PSCS", "t"=>"Probability and Statistics for CS", "u"=>3], ["c"=>"ARCO", "t"=>"Architecture and Organization", "u"=>3], ["c"=>"OS", "t"=>"Operating Systems", "u"=>3], ["c"=>"ATFL", "t"=>"Automata Theory and Formal Languages", "u"=>3], ["c"=>"SE1", "t"=>"Software Engineering 1", "u"=>3], ["c"=>"SE2", "t"=>"Software Engineering 2", "u"=>3], ["c"=>"DAA", "t"=>"Design and Analysis of Algorithms", "u"=>3], ["c"=>"PL", "t"=>"Programming Languages", "u"=>3], ["c"=>"NC", "t"=>"Networks and Communications", "u"=>3], ["c"=>"CST1", "t"=>"CS Thesis 1", "u"=>3], ["c"=>"CST2", "t"=>"CS Thesis 2", "u"=>3]
    ],
    "BS Information Technology" => [
        ["c"=>"ITC", "t"=>"Introduction to Computing", "u"=>3], ["c"=>"CP1", "t"=>"Computer Programming 1", "u"=>3], ["c"=>"CP2", "t"=>"Computer Programming 2", "u"=>3], ["c"=>"DSA", "t"=>"Data Structures", "u"=>3], ["c"=>"SIA", "t"=>"System Integration and Architecture", "u"=>3], ["c"=>"NET1", "t"=>"Networking 1", "u"=>3], ["c"=>"NET2", "t"=>"Networking 2", "u"=>3], ["c"=>"DBMS1", "t"=>"Database Management Systems 1", "u"=>3], ["c"=>"DBMS2", "t"=>"Database Management Systems 2", "u"=>3], ["c"=>"WST", "t"=>"Web Systems and Technologies", "u"=>3], ["c"=>"IM", "t"=>"Information Management", "u"=>3], ["c"=>"SAM", "t"=>"Systems Administration and Maintenance", "u"=>3], ["c"=>"IAS", "t"=>"Information Assurance and Security", "u"=>3], ["c"=>"CAP1", "t"=>"Capstone Project 1", "u"=>3], ["c"=>"CAP2", "t"=>"Capstone Project 2", "u"=>3], ["c"=>"ITINT", "t"=>"IT Internship", "u"=>6]
    ],
    "BS Engineering" => [
        ["c"=>"CA", "t"=>"College Algebra", "u"=>3], ["c"=>"AG", "t"=>"Analytic Geometry", "u"=>3], ["c"=>"SM", "t"=>"Solid Mensuration", "u"=>3], ["c"=>"DC", "t"=>"Differential Calculus", "u"=>3], ["c"=>"IC", "t"=>"Integral Calculus", "u"=>3], ["c"=>"DE", "t"=>"Differential Equations", "u"=>3], ["c"=>"EDA", "t"=>"Engineering Data Analysis", "u"=>3], ["c"=>"GC", "t"=>"General Chemistry", "u"=>3], ["c"=>"UP1", "t"=>"University Physics 1", "u"=>3], ["c"=>"UP2", "t"=>"University Physics 2", "u"=>3], ["c"=>"ED", "t"=>"Engineering Drawings / CAD", "u"=>3], ["c"=>"CF", "t"=>"Computer Fundamentals", "u"=>3], ["c"=>"SRB", "t"=>"Statics of Rigid Bodies", "u"=>3], ["c"=>"DRB", "t"=>"Dynamics of Rigid Bodies", "u"=>3], ["c"=>"MDB", "t"=>"Mechanics of Deformable Bodies", "u"=>3], ["c"=>"EE", "t"=>"Engineering Economics", "u"=>3], ["c"=>"EMGT", "t"=>"Engineering Management", "u"=>3], ["c"=>"TECH", "t"=>"Technopreneurship", "u"=>3],
        ["c"=>"SURV", "t"=>"Surveying (Civil Track)", "u"=>3], ["c"=>"ST", "t"=>"Structural Theory (Civil Track)", "u"=>3], ["c"=>"ME", "t"=>"Materials Engineer (Civil Track)", "u"=>3], ["c"=>"FM", "t"=>"Fluid Mechanics (Civil Track)", "u"=>3], ["c"=>"HYD", "t"=>"Hydraulics (Civil Track)", "u"=>3], ["c"=>"GTE", "t"=>"Geotechnical Engineering (Civil Track)", "u"=>3], ["c"=>"CSD", "t"=>"Concrete and Steel Design (Civil Track)", "u"=>3],
        ["c"=>"TH1", "t"=>"Thermodynamics 1 (Mech Track)", "u"=>3], ["c"=>"TH2", "t"=>"Thermodynamics 2 (Mech Track)", "u"=>3], ["c"=>"FMA", "t"=>"Fluid Machinery (Mech Track)", "u"=>3], ["c"=>"HT", "t"=>"Heat Transfer (Mech Track)", "u"=>3], ["c"=>"MD1", "t"=>"Machine Design 1 (Mech Track)", "u"=>3], ["c"=>"MD2", "t"=>"Machine Design 2 (Mech Track)", "u"=>3], ["c"=>"RAC", "t"=>"Refrigeration and Air Conditioning (Mech Track)", "u"=>3], ["c"=>"PPE", "t"=>"Power Plant Engineering (Mech Track)", "u"=>3],
        ["c"=>"EC1", "t"=>"Electrical Circuits 1 (Elec Track)", "u"=>3], ["c"=>"EC2", "t"=>"Electrical Circuits 2 (Elec Track)", "u"=>3], ["c"=>"ELM", "t"=>"Electromagnetics (Elec Track)", "u"=>3], ["c"=>"EMA1", "t"=>"Electrical Machines 1 (Elec Track)", "u"=>3], ["c"=>"EMA2", "t"=>"Electrical Machines 2 (Elec Track)", "u"=>3], ["c"=>"PSA", "t"=>"Power System Analysis (Elec Track)", "u"=>3], ["c"=>"ELC", "t"=>"Electronic Circuits (Elec Track)", "u"=>3], ["c"=>"CSDE", "t"=>"Control Systems Design (Elec Track)", "u"=>3]
    ],
    "BS Architecture" => [
        ["c"=>"AD1", "t"=>"Architectural Design 1", "u"=>3], ["c"=>"AD2", "t"=>"Architectural Design 2", "u"=>3], ["c"=>"AD3", "t"=>"Architectural Design 3", "u"=>3], ["c"=>"AD4", "t"=>"Architectural Design 4", "u"=>3], ["c"=>"AD5", "t"=>"Architectural Design 5", "u"=>3], ["c"=>"AD6", "t"=>"Architectural Design 6", "u"=>3], ["c"=>"AD7", "t"=>"Architectural Design 7", "u"=>3], ["c"=>"AD8", "t"=>"Architectural Design 8", "u"=>3], ["c"=>"AD9", "t"=>"Architectural Design 9", "u"=>3], ["c"=>"AD10", "t"=>"Architectural Design 10", "u"=>3], ["c"=>"GRA1", "t"=>"Graphics 1", "u"=>3], ["c"=>"GRA2", "t"=>"Graphics 2", "u"=>3], ["c"=>"VT1", "t"=>"Visual Techniques 1", "u"=>3], ["c"=>"VT2", "t"=>"Visual Techniques 2", "u"=>3], ["c"=>"VT3", "t"=>"Visual Techniques 3", "u"=>3], ["c"=>"HOA1", "t"=>"History of Architecture 1", "u"=>3], ["c"=>"HOA2", "t"=>"History of Architecture 2", "u"=>3], ["c"=>"HOA3", "t"=>"History of Architecture 3", "u"=>3], ["c"=>"TOA1", "t"=>"Theory of Architecture 1", "u"=>3], ["c"=>"TOA2", "t"=>"Theory of Architecture 2", "u"=>3], ["c"=>"BT1", "t"=>"Building Technology 1", "u"=>3], ["c"=>"BT2", "t"=>"Building Technology 2", "u"=>3], ["c"=>"BT3", "t"=>"Building Technology 3", "u"=>3], ["c"=>"BT4", "t"=>"Building Technology 4", "u"=>3], ["c"=>"BT5", "t"=>"Building Technology 5", "u"=>3], ["c"=>"BU1", "t"=>"Building Utilities 1", "u"=>3], ["c"=>"BU2", "t"=>"Building Utilities 2", "u"=>3], ["c"=>"BU3", "t"=>"Building Utilities 3", "u"=>3], ["c"=>"AST", "t"=>"Architectural Structures", "u"=>3], ["c"=>"PP", "t"=>"Professional Practice", "u"=>3], ["c"=>"ATH1", "t"=>"Architectural Thesis 1", "u"=>3], ["c"=>"ATH2", "t"=>"Architectural Thesis 2", "u"=>3]
    ],
    "BS Nursing" => [
        ["c"=>"ANP", "t"=>"Anatomy and Physiology", "u"=>3], ["c"=>"MB", "t"=>"Microchemistry and Biochemistry", "u"=>3], ["c"=>"MP", "t"=>"Microbiology and Parasitology", "u"=>3], ["c"=>"TFN", "t"=>"Theoretical Foundations of Nursing", "u"=>3], ["c"=>"HA", "t"=>"Health Assessment", "u"=>3], ["c"=>"CHN1", "t"=>"Community Health Nursing 1", "u"=>3], ["c"=>"CHN2", "t"=>"Community Health Nursing 2", "u"=>3], ["c"=>"PHM", "t"=>"Pharmacology", "u"=>3], ["c"=>"NDT", "t"=>"Nutrition and Diet Therapy", "u"=>3], ["c"=>"CMCF", "t"=>"Care of Mother, Child, and Family", "u"=>3], ["c"=>"CAAHS", "t"=>"Care of Adults with Altered Health States", "u"=>3], ["c"=>"MHPN", "t"=>"Mental Health and Psychiatric Nursing", "u"=>3], ["c"=>"NR1", "t"=>"Nursing Research 1", "u"=>3], ["c"=>"NR2", "t"=>"Nursing Research 2", "u"=>3], ["c"=>"EDN", "t"=>"Emergency and Disaster Nursing", "u"=>3], ["c"=>"RLE", "t"=>"Related Learning Experience (Hospital Duty)", "u"=>6]
    ],
    "BS Psychology" => [
        ["c"=>"GP", "t"=>"General Psychology", "u"=>3], ["c"=>"PSTAT", "t"=>"Psychological Statistics", "u"=>3], ["c"=>"EP", "t"=>"Experimental Psychology", "u"=>3], ["c"=>"RMP1", "t"=>"Research Methods in Psychology 1", "u"=>3], ["c"=>"RMP2", "t"=>"Research Methods in Psychology 2", "u"=>3], ["c"=>"DP", "t"=>"Developmental Psychology", "u"=>3], ["c"=>"TOP", "t"=>"Theories of Personality", "u"=>3], ["c"=>"AP", "t"=>"Abnormal Psychology", "u"=>3], ["c"=>"SP", "t"=>"Social Psychology", "u"=>3], ["c"=>"CP", "t"=>"Cognitive Psychology", "u"=>3], ["c"=>"PBP", "t"=>"Physiological / Biological Psychology", "u"=>3], ["c"=>"PAP", "t"=>"Psychological Assessment / Psychometrics", "u"=>3], ["c"=>"IOP", "t"=>"Industrial/Organizational Psychology", "u"=>3], ["c"=>"CLP", "t"=>"Clinical Psychology", "u"=>3], ["c"=>"FMP", "t"=>"Field Methods / Practicum in Psychology", "u"=>6]
    ],
    "AB Communication/Journalism" => [
        ["c"=>"ICM", "t"=>"Introduction to Communication Media", "u"=>3], ["c"=>"MCS", "t"=>"Media, Culture, and Society", "u"=>3], ["c"=>"CT", "t"=>"Communication Theory", "u"=>3], ["c"=>"CR", "t"=>"Communication Research", "u"=>3], ["c"=>"CMLE", "t"=>"Communication Media Laws and Ethics", "u"=>3], ["c"=>"NW", "t"=>"News Writing", "u"=>3], ["c"=>"BJ", "t"=>"Broadcast Journalism", "u"=>3], ["c"=>"IJ", "t"=>"Investigative Journalism", "u"=>3], ["c"=>"PJ", "t"=>"Photojournalism", "u"=>3], ["c"=>"RTP", "t"=>"Radio and TV Production", "u"=>3], ["c"=>"DMP", "t"=>"Digital Media Production", "u"=>3], ["c"=>"LT", "t"=>"Layout and Typography", "u"=>3], ["c"=>"CINT", "t"=>"Communication Internship", "u"=>6]
    ],
    "AB Political Science" => [
        ["c"=>"IPS", "t"=>"Introduction to Political Science", "u"=>3], ["c"=>"IPA", "t"=>"Introduction to Political Analysis", "u"=>3], ["c"=>"PGP", "t"=>"Philippine Government and Politics", "u"=>3], ["c"=>"HPT", "t"=>"History of Political Thought", "u"=>3], ["c"=>"CGP", "t"=>"Comparative Government and Politics", "u"=>3], ["c"=>"IRWP", "t"=>"International Relations and World Politics", "u"=>3], ["c"=>"PMR", "t"=>"Political Methodology / Research", "u"=>3], ["c"=>"PIL", "t"=>"Public International Law", "u"=>3], ["c"=>"PLG", "t"=>"Philippine Local Governance", "u"=>3], ["c"=>"POD", "t"=>"Politics of Development", "u"=>3]
    ],
    "BA Fine Arts/Multimedia Arts" => [
        ["c"=>"VP", "t"=>"Visual Perceptions", "u"=>3], ["c"=>"DRAW1", "t"=>"Drawing 1", "u"=>3], ["c"=>"DRAW2", "t"=>"Drawing 2", "u"=>3], ["c"=>"CTH", "t"=>"Color Theory", "u"=>3], ["c"=>"HOA1", "t"=>"History of Art 1", "u"=>3], ["c"=>"HOA2", "t"=>"History of Art 2", "u"=>3], ["c"=>"2DD", "t"=>"2D Digital Design", "u"=>3], ["c"=>"3DMA", "t"=>"3D Modeling and Animation", "u"=>3], ["c"=>"DPH", "t"=>"Digital Photography", "u"=>3], ["c"=>"VPE", "t"=>"Video Production and Editing", "u"=>3], ["c"=>"WDS", "t"=>"Web Design and Scripting", "u"=>3], ["c"=>"GDT", "t"=>"Graphic Design and Typography", "u"=>3], ["c"=>"SD", "t"=>"Sound Design", "u"=>3], ["c"=>"PPE", "t"=>"Post-Production Effects", "u"=>3], ["c"=>"MSP", "t"=>"Multimedia Seminar and Portfolio", "u"=>3], ["c"=>"IMD", "t"=>"Interactive Media Design", "u"=>3]
    ],
    "Bachelor in Elementary Education" => [
        ["c"=>"CAL", "t"=>"Child and Adolescent Learners and Learning Principles", "u"=>3], ["c"=>"TTP", "t"=>"The Teaching Profession", "u"=>3], ["c"=>"TTC", "t"=>"The Teacher and the Community", "u"=>3], ["c"=>"FSIE", "t"=>"Foundations of Special and Inclusive Education", "u"=>3], ["c"=>"FLCT", "t"=>"Facilitating Learner-Centered Teaching", "u"=>3], ["c"=>"AOL1", "t"=>"Assessment of Learning 1", "u"=>3], ["c"=>"AOL2", "t"=>"Assessment of Learning 2", "u"=>3], ["c"=>"TTL1", "t"=>"Technology for Teaching and Learning 1", "u"=>3], ["c"=>"FS1", "t"=>"Field Study 1", "u"=>3], ["c"=>"FS2", "t"=>"Field Study 2", "u"=>3], ["c"=>"PT", "t"=>"Practice Teaching", "u"=>6],
        ["c"=>"ETE", "t"=>"Enhanced Teaching of English", "u"=>3], ["c"=>"TMM", "t"=>"Teaching Mathematics in Primary Grades", "u"=>3], ["c"=>"PEP", "t"=>"Pagtuturo ng Edukasyon sa Pagpapakatao", "u"=>3], ["c"=>"TSS", "t"=>"Teaching Social Studies", "u"=>3], ["c"=>"TSC", "t"=>"Teaching Science", "u"=>3], ["c"=>"TMAP", "t"=>"Teaching Music, Arts, PE, and Health", "u"=>3], ["c"=>"REE", "t"=>"Research in Elementary Education", "u"=>3]
    ],
    "Bachelor in Secondary Education" => [
        ["c"=>"CAL", "t"=>"Child and Adolescent Learners and Learning Principles", "u"=>3], ["c"=>"TTP", "t"=>"The Teaching Profession", "u"=>3], ["c"=>"TTC", "t"=>"The Teacher and the Community", "u"=>3], ["c"=>"FSIE", "t"=>"Foundations of Special and Inclusive Education", "u"=>3], ["c"=>"FLCT", "t"=>"Facilitating Learner-Centered Teaching", "u"=>3], ["c"=>"AOL1", "t"=>"Assessment of Learning 1", "u"=>3], ["c"=>"AOL2", "t"=>"Assessment of Learning 2", "u"=>3], ["c"=>"TTL1", "t"=>"Technology for Teaching and Learning 1", "u"=>3], ["c"=>"FS1", "t"=>"Field Study 1", "u"=>3], ["c"=>"FS2", "t"=>"Field Study 2", "u"=>3], ["c"=>"PT", "t"=>"Practice Teaching", "u"=>6],
        ["c"=>"SOE", "t"=>"Structure of English", "u"=>3], ["c"=>"MAF", "t"=>"Mythology and Folklore", "u"=>3], ["c"=>"SPL", "t"=>"Survey of Philippine Literature", "u"=>3], ["c"=>"LC", "t"=>"Literary Criticism", "u"=>3], ["c"=>"LLMD", "t"=>"Language Learning Materials Development", "u"=>3],
        ["c"=>"MG", "t"=>"Modern Geometry", "u"=>3], ["c"=>"LAL", "t"=>"Linear Algebra", "u"=>3], ["c"=>"AC", "t"=>"Advanced Calculus", "u"=>3], ["c"=>"TRG", "t"=>"Trigonometry", "u"=>3], ["c"=>"AA", "t"=>"Abstract Algebra", "u"=>3], ["c"=>"NT", "t"=>"Number Theory", "u"=>3],
        ["c"=>"ES", "t"=>"Earth Science", "u"=>3], ["c"=>"MET", "t"=>"Meteorology", "u"=>3], ["c"=>"ORGC", "t"=>"Organic Chemistry", "u"=>3], ["c"=>"INOC", "t"=>"Inorganic Chemistry", "u"=>3], ["c"=>"GAE", "t"=>"Genetics and Evolution", "u"=>3], ["c"=>"MEC", "t"=>"Mechanics", "u"=>3], ["c"=>"WAO", "t"=>"Waves and Optics", "u"=>3],
        ["c"=>"AS", "t"=>"Asian Studies", "u"=>3], ["c"=>"WH", "t"=>"World History", "u"=>3], ["c"=>"GEO", "t"=>"Geography", "u"=>3], ["c"=>"MME", "t"=>"Micro/Macroeconomics", "u"=>3], ["c"=>"SCA", "t"=>"Socio-Cultural Anthropology", "u"=>3]
    ],
    "Bachelor of Early Childhood Education" => [
        ["c"=>"CAL", "t"=>"Child and Adolescent Learners and Learning Principles", "u"=>3], ["c"=>"TTP", "t"=>"The Teaching Profession", "u"=>3], ["c"=>"TTC", "t"=>"The Teacher and the Community", "u"=>3], ["c"=>"FSIE", "t"=>"Foundations of Special and Inclusive Education", "u"=>3], ["c"=>"FLCT", "t"=>"Facilitating Learner-Centered Teaching", "u"=>3], ["c"=>"AOL1", "t"=>"Assessment of Learning 1", "u"=>3], ["c"=>"AOL2", "t"=>"Assessment of Learning 2", "u"=>3], ["c"=>"TTL1", "t"=>"Technology for Teaching and Learning 1", "u"=>3], ["c"=>"FS1", "t"=>"Field Study 1", "u"=>3], ["c"=>"FS2", "t"=>"Field Study 2", "u"=>3], ["c"=>"PT", "t"=>"Practice Teaching", "u"=>6],
        ["c"=>"FECE", "t"=>"Foundations of Early Childhood Education", "u"=>3], ["c"=>"CAMD", "t"=>"Creative Arts, Music, and Drama", "u"=>3], ["c"=>"LLD", "t"=>"Language and Literacy Development", "u"=>3], ["c"=>"SME", "t"=>"Science and Math in Early Childhood", "u"=>3], ["c"=>"GCB", "t"=>"Guiding Children's Behavior", "u"=>3], ["c"=>"HSN", "t"=>"Health, Safety, and Nutrition", "u"=>3], ["c"=>"IEEC", "t"=>"Inclusive Education in Early Childhood", "u"=>3], ["c"=>"CGD", "t"=>"Child Growth and Development", "u"=>3]
    ]
];

if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_GET['delete_user_id'])) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_GET['delete_user_id']]);
        $msg = "<div class='alert alert-danger text-dark'>User account has been permanently removed.</div>";
    }
    if (isset($_GET['approve_id'])) {
        $pdo->prepare("UPDATE users SET status='approved' WHERE id=?")->execute([$_GET['approve_id']]);
        
        // AUTO-ENROLL STUDENT UPON APPROVAL
        $uStmt = $pdo->prepare("SELECT id, course FROM users WHERE id=?");
        $uStmt->execute([$_GET['approve_id']]);
        $stu = $uStmt->fetch();
        
        if ($stu && !empty($stu['course'])) {
            $subs = $pdo->prepare("SELECT id FROM subjects WHERE course = ? OR course = 'Universal Standard Subjects'");
            $subs->execute([$stu['course']]);
            foreach($subs->fetchAll() as $sub) {
                // Ensure duplicate enrollments do not occur
                $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
                $check->execute([$stu['id'], $sub['id']]);
                if(!$check->fetch()) {
                    $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)")->execute([$stu['id'], $sub['id']]);
                }
            }
        }
        $msg = "<div class='alert alert-success text-dark'>User Approved & Auto-Enrolled into assigned curriculum subjects!</div>";
    }
    if (isset($_GET['reject_id'])) {
        $pdo->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$_GET['reject_id']]);
        $msg = "<div class='alert alert-danger text-dark'>User Rejected.</div>";
    }
    if (isset($_POST['create_staff'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $course = ($role == 'teacher' && !empty($_POST['course'])) ? $_POST['course'] : null;

        try {
            $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role, status, course) VALUES (?, ?, ?, ?, ?, 'approved', ?) RETURNING id");
            $stmt->execute([$_POST['fname'], $_POST['lname'], $_POST['user'], $hash, $role, $course]);
            $new_teacher_id = $stmt->fetchColumn();

            // AUTO-ASSIGN SUBJECTS IF TEACHER
            if ($role == 'teacher' && $course && isset($curriculumData[$course])) {
                foreach ($curriculumData[$course] as $subj) {
                    // Check if subject already exists
                    $check = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ? AND course = ?");
                    $check->execute([$subj['c'], $course]);
                    $existing_sub_id = $check->fetchColumn();

                    if ($existing_sub_id) {
                        // Update existing subject with new teacher
                        $pdo->prepare("UPDATE subjects SET teacher_id = ? WHERE id = ?")->execute([$new_teacher_id, $existing_sub_id]);
                    } else {
                        // Insert missing subject to curriculum
                        $ins = $pdo->prepare("INSERT INTO subjects (subject_code, subject_title, units, sy, sem, course, teacher_id, schedule) VALUES (?, ?, ?, '2024-2025', '1st', ?, ?, 'TBA') RETURNING id");
                        $ins->execute([$subj['c'], $subj['t'], $subj['u'], $course, $new_teacher_id]);
                        $new_sub_id = $ins->fetchColumn();

                        // Auto-enroll eligible students into this newly generated subject
                        $st = $pdo->prepare("SELECT id FROM users WHERE role='student' AND status='approved' AND (course=? OR ?='Universal Standard Subjects')");
                        $st->execute([$course, $course]);
                        foreach($st->fetchAll() as $stu) {
                            $echeck = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
                            $echeck->execute([$stu['id'], $new_sub_id]);
                            if(!$echeck->fetch()) {
                                $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)")->execute([$stu['id'], $new_sub_id]);
                            }
                        }
                    }
                }
                $msg = "<div class='alert alert-success text-dark'>Teacher created and dynamically assigned all curriculum subjects for {$course}!</div>";
            } else {
                $msg = "<div class='alert alert-success text-dark'>Staff account created successfully.</div>";
            }
        } catch (PDOException $e) {
            // Catch unique constraint violations (23505 in PostgreSQL, 1062 in MySQL)
            if ($e->getCode() == 23505 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
                $msg = "<div class='alert alert-danger text-dark'>Error: The username '{$_POST['user']}' is already taken. Please choose a different username.</div>";
            } else {
                $msg = "<div class='alert alert-danger text-dark'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
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

        /* MUSIC SYSTEM DECK STYLING */
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
						<h4 class="mb-3">Shared Music Room</h4>

    <input
        type="text"
        id="songInput"
        class="form-control mb-3"
        placeholder="Paste MP3 URL here"
    >

    <button onclick="loadSong()" class="btn btn-orange mb-3">
        Play Shared Song
    </button>

    <audio
        id="sharedPlayer"
        controls
        style="width:100%;"
    ></audio>

</div>
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
                // --- MY TASKS & WORKSPACE DASHBOARD ---
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

                        <!-- EMBEDDED MUSIC PLAYER -->
                        <div class="col-lg-6 mb-4">
                            <div class="glass-panel p-4 h-100 d-flex flex-column" id="workspaceMusicPlayer">
                                <h5 class="mb-4 fw-semibold"><i class="bi bi-boombox me-2"></i>Audio Deck</h5>
                                
                                <div class="deck-wrapper mb-4 flex-grow-0" style="padding: 16px;">
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

                                <div class="flex-grow-1 d-flex flex-column">
                                    <h6 class="small fw-semibold text-white-50 mb-2"><i class="bi bi-folder-plus me-1"></i>Import Audio Files</h6>
                                    <div class="mb-3">
                                        <input type="file" id="localAudioPicker" class="form-control form-control-sm" accept="audio/*" multiple onchange="loadFilesIntoPlaylist(this)">
                                    </div>

                                    <div class="playlist-vault-box flex-grow-1 overflow-auto" style="min-height: 150px; border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 10px; background: rgba(0,0,0,0.2);">
                                        <div id="deckPlaylistTracksContainer" class="d-flex flex-column gap-2">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
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
                            echo "<a href='?page=approvals&approve_id={$p['id']}' class='btn btn-sm btn-success'>Approve & Auto-Enroll</a> ";
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
                            
                            <select name="role" id="staffRoleSelect" class="form-select mb-3" onchange="toggleStaffCourse()">
                                <option value="teacher">Teacher</option>
                                <option value="dean">Dean</option>
                                <option value="records">Records</option>
                                <option value="cashier">Cashier</option>
                                <option value="finance">Finance</option>
                            </select>

                            <div id="staffCourseContainer" class="mb-3">
                                <select name="course" id="staffCourseSelect" class="form-select custom-dark-select">
                                    <option value="">-- Assign a Course to Auto-Generate Subjects --</option>
                                    <option value="Universal Standard Subjects">Universal Standard Subjects</option>
                                    <option value="BS Accountancy">Bachelor of Science in Accountancy (BSA)</option>
                                    <option value="BS Business Administration">BS Business Administration</option>
                                    <option value="BS Entrepreneurship">BS Entrepreneurship</option>
                                    <option value="BS Legal Management">BS Legal Management</option>
                                    <option value="BS Tourism/Hospitality Management">BS Tourism/Hospitality Management</option>
                                    <option value="BS Computer Science">BS Computer Science</option>
                                    <option value="BS Information Technology">BS Information Technology</option>
                                    <option value="BS Engineering">BS Civil/Mechanical/Electrical Engineering</option>
                                    <option value="BS Architecture">BS Architecture</option>
                                    <option value="BS Nursing">BS Nursing</option>
                                    <option value="BS Psychology">BS Psychology</option>
                                    <option value="AB Communication/Journalism">AB Communication/Journalism</option>
                                    <option value="AB Political Science">AB Political Science</option>
                                    <option value="BA Fine Arts/Multimedia Arts">BA Fine Arts/Multimedia Arts</option>
                                    <option value="Bachelor in Elementary Education">Bachelor in Elementary Education (BEED)</option>
                                    <option value="Bachelor in Secondary Education">Bachelor in Secondary Education (BSEd)</option>
                                    <option value="Bachelor of Early Childhood Education">Bachelor of Early Childhood Education (BECED)</option>
                                </select>
                                <small class="text-white-50">Note: Choosing a course will automatically map this teacher to all of its default subjects.</small>
                            </div>

                            <button name="create_staff" class="btn btn-orange w-100">Register Staff</button>
                        </form>
                    </div>

                    <script>
                        function toggleStaffCourse() {
                            var role = document.getElementById('staffRoleSelect').value;
                            var courseContainer = document.getElementById('staffCourseContainer');
                            if(role === 'teacher') {
                                courseContainer.style.display = 'block';
                            } else {
                                courseContainer.style.display = 'none';
                                document.getElementById('staffCourseSelect').value = '';
                            }
                        }
                        // Fire on load
                        document.addEventListener('DOMContentLoaded', toggleStaffCourse);
                    </script>
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
                            
                            <div class="col-md-2">
                                <input name="sy" placeholder="SY (e.g. 2024-2025)" class="form-control" value="<?= $edit_sub['sy'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-2">
                                <select name="sem" class="form-select">
                                    <option <?= ($edit_sub['sem']??'')=='1st'?'selected':'' ?>>1st</option>
                                    <option <?= ($edit_sub['sem']??'')=='2nd'?'selected':'' ?>>2nd</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="course" id="deanCourseSelect" class="form-select custom-dark-select" required onchange="populateSubjects()">
                                    <option value="" disabled <?= !$edit_sub ? 'selected' : '' ?>>Select Course Category</option>
                                    <option value="Universal Standard Subjects" <?= ($edit_sub['course']??'')=='Universal Standard Subjects'?'selected':'' ?>>Universal Standard Subjects</option>
                                    <option value="BS Accountancy" <?= ($edit_sub['course']??'')=='BS Accountancy'?'selected':'' ?>>BS Accountancy</option>
                                    <option value="BS Business Administration" <?= ($edit_sub['course']??'')=='BS Business Administration'?'selected':'' ?>>BS Business Administration</option>
                                    <option value="BS Entrepreneurship" <?= ($edit_sub['course']??'')=='BS Entrepreneurship'?'selected':'' ?>>BS Entrepreneurship</option>
                                    <option value="BS Legal Management" <?= ($edit_sub['course']??'')=='BS Legal Management'?'selected':'' ?>>BS Legal Management</option>
                                    <option value="BS Tourism/Hospitality Management" <?= ($edit_sub['course']??'')=='BS Tourism/Hospitality Management'?'selected':'' ?>>BS Tourism/Hospitality Management</option>
                                    <option value="BS Computer Science" <?= ($edit_sub['course']??'')=='BS Computer Science'?'selected':'' ?>>BS Computer Science</option>
                                    <option value="BS Information Technology" <?= ($edit_sub['course']??'')=='BS Information Technology'?'selected':'' ?>>BS Information Technology</option>
                                    <option value="BS Engineering" <?= ($edit_sub['course']??'')=='BS Engineering'?'selected':'' ?>>BS Engineering</option>
                                    <option value="BS Architecture" <?= ($edit_sub['course']??'')=='BS Architecture'?'selected':'' ?>>BS Architecture</option>
                                    <option value="BS Nursing" <?= ($edit_sub['course']??'')=='BS Nursing'?'selected':'' ?>>BS Nursing</option>
                                    <option value="BS Psychology" <?= ($edit_sub['course']??'')=='BS Psychology'?'selected':'' ?>>BS Psychology</option>
                                    <option value="AB Communication/Journalism" <?= ($edit_sub['course']??'')=='AB Communication/Journalism'?'selected':'' ?>>AB Communication/Journalism</option>
                                    <option value="AB Political Science" <?= ($edit_sub['course']??'')=='AB Political Science'?'selected':'' ?>>AB Political Science</option>
                                    <option value="BA Fine Arts/Multimedia Arts" <?= ($edit_sub['course']??'')=='BA Fine Arts/Multimedia Arts'?'selected':'' ?>>BA Fine Arts/Multimedia Arts</option>
                                    <option value="Bachelor in Elementary Education" <?= ($edit_sub['course']??'')=='Bachelor in Elementary Education'?'selected':'' ?>>Bachelor in Elementary Education</option>
                                    <option value="Bachelor in Secondary Education" <?= ($edit_sub['course']??'')=='Bachelor in Secondary Education'?'selected':'' ?>>Bachelor in Secondary Education</option>
                                    <option value="Bachelor of Early Childhood Education" <?= ($edit_sub['course']??'')=='Bachelor of Early Childhood Education'?'selected':'' ?>>Bachelor of Early Childhood Education</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select id="deanSubjectPreset" class="form-select custom-dark-select" onchange="fillSubjectDetails()">
                                    <option value="">-- Auto-Fill Subject Data --</option>
                                </select>
                            </div>

                            <div class="col-md-2"><input name="code" id="deanCode" placeholder="Subject Code" class="form-control" value="<?= $edit_sub['subject_code'] ?? '' ?>" required></div>
                            <div class="col-md-8"><input name="title" id="deanTitle" placeholder="Subject Title" class="form-control" value="<?= $edit_sub['subject_title'] ?? '' ?>" required></div>
                            <div class="col-md-2"><input name="units" id="deanUnits" type="number" placeholder="Units" class="form-control" value="<?= $edit_sub['units'] ?? '' ?>" required></div>
                            
                            <div class="col-md-3">
                                <select name="teacher_id" class="form-select custom-dark-select">
                                    <option value="0">Unassigned (Teacher)</option>
                                    <?php 
                                    $techs = $pdo->query("SELECT id, lastname FROM users WHERE role='teacher'")->fetchAll();
                                    foreach($techs as $t) echo "<option value='{$t['id']}' ".($edit_sub['teacher_id']??0 == $t['id']?'selected':'').">{$t['lastname']}</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-7"><input name="schedule" placeholder="Schedule (Optional)" class="form-control" value="<?= $edit_sub['schedule'] ?? '' ?>"></div>
                            <div class="col-md-2"><button name="save_subject" class="btn btn-orange w-100"><?= $edit_sub ? 'Update' : 'Add Subject' ?></button></div>
                        </form>
                    </div>

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
<script>
// --- AUTOMATIC CURRICULUM UI POPULATOR ---
window.curriculumData = {
    "Universal Standard Subjects": [
        {c:"GE-MMW", t:"Mathematics in the Modern World", u:3}, {c:"GE-PC", t:"Purposive Communication", u:3}, {c:"GE-STS", t:"Science, Technology, and Society", u:3}, {c:"GE-CW", t:"Contemporary World", u:3}, {c:"GE-AA", t:"Art Appreciation", u:3}, {c:"GE-UTS", t:"Understanding the Self", u:3}, {c:"GE-RPH", t:"Readings in Philippine History", u:3}, {c:"GE-ETH", t:"Ethics", u:3}, {c:"RIZAL", t:"Life and Works of Rizal", u:3}, {c:"NSTP1", t:"National Service Training Program 1", u:3}, {c:"NSTP2", t:"National Service Training Program 2", u:3}, {c:"PE1", t:"PE 1 (Fitness/Wellness)", u:2}, {c:"PE2", t:"PE 2 (Rhythmic Activities)", u:2}, {c:"PE3", t:"PE 3 (Individual/Dual Sports)", u:2}, {c:"PE4", t:"PE 4 (Team Sports)", u:2}
    ],
    "BS Accountancy": [
        {c:"CBB1", t:"Information Technology in Business", u:3}, {c:"CBB2", t:"Microeconomics", u:3}, {c:"CBB3", t:"Business Law (Obligations and Contracts)", u:3}, {c:"CBB4", t:"Income Taxation", u:3}, {c:"CBB5", t:"Strategic Management", u:3}, {c:"CBB6", t:"Good Governance and Social Responsibility", u:3}, {c:"CBB7", t:"Total Quality Management", u:3}, {c:"CBB8", t:"Human Resource Management", u:3},
        {c:"FAR", t:"Financial Accounting and Reporting", u:3}, {c:"CAC", t:"Cost Accounting and Control", u:3}, {c:"IA1", t:"Intermediate Accounting 1", u:3}, {c:"IA2", t:"Intermediate Accounting 2", u:3}, {c:"IA3", t:"Intermediate Accounting 3", u:3}, {c:"CFAS", t:"Conceptual Framework and Accounting Standards", u:3}, {c:"AFAR1", t:"Advanced Financial Accounting and Reporting 1", u:3}, {c:"AFAR2", t:"Advanced Financial Accounting and Reporting 2", u:3}, {c:"MAC", t:"Management Accounting", u:3}, {c:"FM", t:"Financial Management", u:3}, {c:"MAS", t:"Management Advisory Services", u:3}, {c:"AAP", t:"Auditing and Assurance Principles", u:3}, {c:"AASI", t:"Auditing and Assurance: Specialized Industries", u:3}, {c:"ACIS", t:"Audit in a CIS/IT Environment", u:3}, {c:"BTAX", t:"Business Tax", u:3}, {c:"TBT", t:"Transfer and Business Taxation", u:3}, {c:"RFBT", t:"Regulatory Framework for Business Transactions", u:3}, {c:"ARM", t:"Accounting Research Methods", u:3}, {c:"AINT", t:"Accounting Internship", u:6}
    ],
    "BS Business Administration": [
        {c:"CBB1", t:"Information Technology in Business", u:3}, {c:"CBB2", t:"Microeconomics", u:3}, {c:"CBB3", t:"Business Law (Obligations and Contracts)", u:3}, {c:"CBB4", t:"Income Taxation", u:3}, {c:"CBB5", t:"Strategic Management", u:3}, {c:"CBB6", t:"Good Governance and Social Responsibility", u:3}, {c:"CBB7", t:"Total Quality Management", u:3}, {c:"CBB8", t:"Human Resource Management", u:3},
        {c:"POM", t:"Principles of Marketing", u:3}, {c:"MM", t:"Marketing Management", u:3}, {c:"OM", t:"Operations Management", u:3}, {c:"BRM", t:"Business Research Methods", u:3}, {c:"FM", t:"Financial Management", u:3}, {c:"PS", t:"Pricing Strategy", u:3}, {c:"CB", t:"Consumer Behavior", u:3}, {c:"PROS", t:"Professional Salesmanship", u:3}, {c:"BSIM", t:"Business Simulation", u:3}, {c:"BINT", t:"Practicum/Internship", u:6}
    ],
    "BS Entrepreneurship": [
        {c:"CBB1", t:"Information Technology in Business", u:3}, {c:"CBB2", t:"Microeconomics", u:3}, {c:"CBB3", t:"Business Law (Obligations and Contracts)", u:3}, {c:"CBB4", t:"Income Taxation", u:3}, {c:"CBB5", t:"Strategic Management", u:3}, {c:"CBB6", t:"Good Governance and Social Responsibility", u:3}, {c:"CBB7", t:"Total Quality Management", u:3}, {c:"CBB8", t:"Human Resource Management", u:3},
        {c:"EM", t:"Entrepreneurial Mindset", u:3}, {c:"OSE", t:"Opportunity Spotting and Evaluation", u:3}, {c:"MRCB", t:"Market Research and Consumer Behavior", u:3}, {c:"BPP", t:"Business Plan Preparation", u:3}, {c:"PDI", t:"Product Development and Innovation", u:3}, {c:"BPI1", t:"Business Plan Implementation 1", u:3}, {c:"BPI2", t:"Business Plan Implementation 2", u:3}, {c:"VCD", t:"Venture Capital and Development", u:3}, {c:"SBM", t:"Small Business Management", u:3}, {c:"EMKT", t:"Entrepreneurial Marketing", u:3}
    ],
    "BS Legal Management": [
        {c:"CBB1", t:"Information Technology in Business", u:3}, {c:"CBB2", t:"Microeconomics", u:3}, {c:"CBB3", t:"Business Law (Obligations and Contracts)", u:3}, {c:"CBB4", t:"Income Taxation", u:3}, {c:"CBB5", t:"Strategic Management", u:3}, {c:"CBB6", t:"Good Governance and Social Responsibility", u:3}, {c:"CBB7", t:"Total Quality Management", u:3}, {c:"CBB8", t:"Human Resource Management", u:3},
        {c:"CLAW", t:"Constitutional Law", u:3}, {c:"LBO", t:"Law on Business Organizations", u:3}, {c:"LLL", t:"Labor Law and Legislation", u:3}, {c:"SACT", t:"Sales, Agency, and Credit Transactions", u:3}, {c:"NIL", t:"Negotiable Instruments Law", u:3}, {c:"ALAW", t:"Administrative Law", u:3}, {c:"IPL", t:"Intellectual Property Law", u:3}, {c:"SCON", t:"Statutory Construction", u:3}, {c:"LRW", t:"Legal Research and Writing", u:3}, {c:"TLAW", t:"Taxation Law", u:3}
    ],
    "BS Tourism/Hospitality Management": [
        {c:"MMPT", t:"Macro/Micro Perspective of Tourism & Hospitality", u:3}, {c:"TPG", t:"Tourism Policy and Governance", u:3}, {c:"TTO", t:"Tour and Travel Operations", u:3}, {c:"GCG", t:"Global Culture and Geography", u:3}, {c:"TMGT", t:"Transportation Management", u:3}, {c:"FOO", t:"Front Office Operations", u:3}, {c:"KE", t:"Kitchen Essentials", u:3}, {c:"FBSO", t:"Food & Beverage Service Operations", u:3}, {c:"AO", t:"Accommodation Operations", u:3}, {c:"BCM", t:"Banquet and Catering Management", u:3}, {c:"EVM", t:"Event Management", u:3}, {c:"THP", t:"Tourism/Hospitality Practicum", u:6}
    ],
    "BS Computer Science": [
        {c:"ITC", t:"Introduction to Computing", u:3}, {c:"CP1", t:"Computer Programming 1", u:3}, {c:"CP2", t:"Computer Programming 2", u:3}, {c:"DSA", t:"Data Structures and Algorithms", u:3}, {c:"DM", t:"Discrete Mathematics", u:3}, {c:"CCS", t:"Calculus for Computer Science", u:3}, {c:"LA", t:"Linear Algebra", u:3}, {c:"PSCS", t:"Probability and Statistics for CS", u:3}, {c:"ARCO", t:"Architecture and Organization", u:3}, {c:"OS", t:"Operating Systems", u:3}, {c:"ATFL", t:"Automata Theory and Formal Languages", u:3}, {c:"SE1", t:"Software Engineering 1", u:3}, {c:"SE2", t:"Software Engineering 2", u:3}, {c:"DAA", t:"Design and Analysis of Algorithms", u:3}, {c:"PL", t:"Programming Languages", u:3}, {c:"NC", t:"Networks and Communications", u:3}, {c:"CST1", t:"CS Thesis 1", u:3}, {c:"CST2", t:"CS Thesis 2", u:3}
    ],
    "BS Information Technology": [
        {c:"ITC", t:"Introduction to Computing", u:3}, {c:"CP1", t:"Computer Programming 1", u:3}, {c:"CP2", t:"Computer Programming 2", u:3}, {c:"DSA", t:"Data Structures", u:3}, {c:"SIA", t:"System Integration and Architecture", u:3}, {c:"NET1", t:"Networking 1", u:3}, {c:"NET2", t:"Networking 2", u:3}, {c:"DBMS1", t:"Database Management Systems 1", u:3}, {c:"DBMS2", t:"Database Management Systems 2", u:3}, {c:"WST", t:"Web Systems and Technologies", u:3}, {c:"IM", t:"Information Management", u:3}, {c:"SAM", t:"Systems Administration and Maintenance", u:3}, {c:"IAS", t:"Information Assurance and Security", u:3}, {c:"CAP1", t:"Capstone Project 1", u:3}, {c:"CAP2", t:"Capstone Project 2", u:3}, {c:"ITINT", t:"IT Internship", u:6}
    ],
    "BS Engineering": [
        {c:"CA", t:"College Algebra", u:3}, {c:"AG", t:"Analytic Geometry", u:3}, {c:"SM", t:"Solid Mensuration", u:3}, {c:"DC", t:"Differential Calculus", u:3}, {c:"IC", t:"Integral Calculus", u:3}, {c:"DE", t:"Differential Equations", u:3}, {c:"EDA", t:"Engineering Data Analysis", u:3}, {c:"GC", t:"General Chemistry", u:3}, {c:"UP1", t:"University Physics 1", u:3}, {c:"UP2", t:"University Physics 2", u:3}, {c:"ED", t:"Engineering Drawings / CAD", u:3}, {c:"CF", t:"Computer Fundamentals", u:3}, {c:"SRB", t:"Statics of Rigid Bodies", u:3}, {c:"DRB", t:"Dynamics of Rigid Bodies", u:3}, {c:"MDB", t:"Mechanics of Deformable Bodies", u:3}, {c:"EE", t:"Engineering Economics", u:3}, {c:"EMGT", t:"Engineering Management", u:3}, {c:"TECH", t:"Technopreneurship", u:3},
        {c:"SURV", t:"Surveying (Civil Track)", u:3}, {c:"ST", t:"Structural Theory (Civil Track)", u:3}, {c:"ME", t:"Materials Engineer (Civil Track)", u:3}, {c:"FM", t:"Fluid Mechanics (Civil Track)", u:3}, {c:"HYD", t:"Hydraulics (Civil Track)", u:3}, {c:"GTE", t:"Geotechnical Engineering (Civil Track)", u:3}, {c:"CSD", t:"Concrete and Steel Design (Civil Track)", u:3},
        {c:"TH1", t:"Thermodynamics 1 (Mech Track)", u:3}, {c:"TH2", t:"Thermodynamics 2 (Mech Track)", u:3}, {c:"FMA", t:"Fluid Machinery (Mech Track)", u:3}, {c:"HT", t:"Heat Transfer (Mech Track)", u:3}, {c:"MD1", t:"Machine Design 1 (Mech Track)", u:3}, {c:"MD2", t:"Machine Design 2 (Mech Track)", u:3}, {c:"RAC", t:"Refrigeration and Air Conditioning (Mech Track)", u:3}, {c:"PPE", t:"Power Plant Engineering (Mech Track)", u:3},
        {c:"EC1", t:"Electrical Circuits 1 (Elec Track)", u:3}, {c:"EC2", t:"Electrical Circuits 2 (Elec Track)", u:3}, {c:"ELM", t:"Electromagnetics (Elec Track)", u:3}, {c:"EMA1", t:"Electrical Machines 1 (Elec Track)", u:3}, {c:"EMA2", t:"Electrical Machines 2 (Elec Track)", u:3}, {c:"PSA", t:"Power System Analysis (Elec Track)", u:3}, {c:"ELC", t:"Electronic Circuits (Elec Track)", u:3}, {c:"CSDE", t:"Control Systems Design (Elec Track)", u:3}
    ],
    "BS Architecture": [
        {c:"AD1", t:"Architectural Design 1", u:3}, {c:"AD2", t:"Architectural Design 2", u:3}, {c:"AD3", t:"Architectural Design 3", u:3}, {c:"AD4", t:"Architectural Design 4", u:3}, {c:"AD5", t:"Architectural Design 5", u:3}, {c:"AD6", t:"Architectural Design 6", u:3}, {c:"AD7", t:"Architectural Design 7", u:3}, {c:"AD8", t:"Architectural Design 8", u:3}, {c:"AD9", t:"Architectural Design 9", u:3}, {c:"AD10", t:"Architectural Design 10", u:3}, {c:"GRA1", t:"Graphics 1", u:3}, {c:"GRA2", t:"Graphics 2", u:3}, {c:"VT1", t:"Visual Techniques 1", u:3}, {c:"VT2", t:"Visual Techniques 2", u:3}, {c:"VT3", t:"Visual Techniques 3", u:3}, {c:"HOA1", t:"History of Architecture 1", u:3}, {c:"HOA2", t:"History of Architecture 2", u:3}, {c:"HOA3", t:"History of Architecture 3", u:3}, {c:"TOA1", t:"Theory of Architecture 1", u:3}, {c:"TOA2", t:"Theory of Architecture 2", u:3}, {c:"BT1", t:"Building Technology 1", u:3}, {c:"BT2", t:"Building Technology 2", u:3}, {c:"BT3", t:"Building Technology 3", u:3}, {c:"BT4", t:"Building Technology 4", u:3}, {c:"BT5", t:"Building Technology 5", u:3}, {c:"BU1", t:"Building Utilities 1", u:3}, {c:"BU2", t:"Building Utilities 2", u:3}, {c:"BU3", t:"Building Utilities 3", u:3}, {c:"AST", t:"Architectural Structures", u:3}, {c:"PP", t:"Professional Practice", u:3}, {c:"ATH1", t:"Architectural Thesis 1", u:3}, {c:"ATH2", t:"Architectural Thesis 2", u:3}
    ],
    "BS Nursing": [
        {c:"ANP", t:"Anatomy and Physiology", u:3}, {c:"MB", t:"Microchemistry and Biochemistry", u:3}, {c:"MP", t:"Microbiology and Parasitology", u:3}, {c:"TFN", t:"Theoretical Foundations of Nursing", u:3}, {c:"HA", t:"Health Assessment", u:3}, {c:"CHN1", t:"Community Health Nursing 1", u:3}, {c:"CHN2", t:"Community Health Nursing 2", u:3}, {c:"PHM", t:"Pharmacology", u:3}, {c:"NDT", t:"Nutrition and Diet Therapy", u:3}, {c:"CMCF", t:"Care of Mother, Child, and Family", u:3}, {c:"CAAHS", t:"Care of Adults with Altered Health States", u:3}, {c:"MHPN", t:"Mental Health and Psychiatric Nursing", u:3}, {c:"NR1", t:"Nursing Research 1", u:3}, {c:"NR2", t:"Nursing Research 2", u:3}, {c:"EDN", t:"Emergency and Disaster Nursing", u:3}, {c:"RLE", t:"Related Learning Experience (Hospital Duty)", u:6}
    ],
    "BS Psychology": [
        {c:"GP", t:"General Psychology", u:3}, {c:"PSTAT", t:"Psychological Statistics", u:3}, {c:"EP", t:"Experimental Psychology", u:3}, {c:"RMP1", t:"Research Methods in Psychology 1", u:3}, {c:"RMP2", t:"Research Methods in Psychology 2", u:3}, {c:"DP", t:"Developmental Psychology", u:3}, {c:"TOP", t:"Theories of Personality", u:3}, {c:"AP", t:"Abnormal Psychology", u:3}, {c:"SP", t:"Social Psychology", u:3}, {c:"CP", t:"Cognitive Psychology", u:3}, {c:"PBP", t:"Physiological / Biological Psychology", u:3}, {c:"PAP", t:"Psychological Assessment / Psychometrics", u:3}, {c:"IOP", t:"Industrial/Organizational Psychology", u:3}, {c:"CLP", t:"Clinical Psychology", u:3}, {c:"FMP", t:"Field Methods / Practicum in Psychology", u:6}
    ],
    "AB Communication/Journalism": [
        {c:"ICM", t:"Introduction to Communication Media", u:3}, {c:"MCS", t:"Media, Culture, and Society", u:3}, {c:"CT", t:"Communication Theory", u:3}, {c:"CR", t:"Communication Research", u:3}, {c:"CMLE", t:"Communication Media Laws and Ethics", u:3}, {c:"NW", t:"News Writing", u:3}, {c:"BJ", t:"Broadcast Journalism", u:3}, {c:"IJ", t:"Investigative Journalism", u:3}, {c:"PJ", t:"Photojournalism", u:3}, {c:"RTP", t:"Radio and TV Production", u:3}, {c:"DMP", t:"Digital Media Production", u:3}, {c:"LT", t:"Layout and Typography", u:3}, {c:"CINT", t:"Communication Internship", u:6}
    ],
    "AB Political Science": [
        {c:"IPS", t:"Introduction to Political Science", u:3}, {c:"IPA", t:"Introduction to Political Analysis", u:3}, {c:"PGP", t:"Philippine Government and Politics", u:3}, {c:"HPT", t:"History of Political Thought", u:3}, {c:"CGP", t:"Comparative Government and Politics", u:3}, {c:"IRWP", t:"International Relations and World Politics", u:3}, {c:"PMR", t:"Political Methodology / Research", u:3}, {c:"PIL", t:"Public International Law", u:3}, {c:"PLG", t:"Philippine Local Governance", u:3}, {c:"POD", t:"Politics of Development", u:3}
    ],
    "BA Fine Arts/Multimedia Arts": [
        {c:"VP", t:"Visual Perceptions", u:3}, {c:"DRAW1", t:"Drawing 1", u:3}, {c:"DRAW2", t:"Drawing 2", u:3}, {c:"CTH", t:"Color Theory", u:3}, {c:"HOA1", t:"History of Art 1", u:3}, {c:"HOA2", t:"History of Art 2", u:3}, {c:"2DD", t:"2D Digital Design", u:3}, {c:"3DMA", t:"3D Modeling and Animation", u:3}, {c:"DPH", t:"Digital Photography", u:3}, {c:"VPE", t:"Video Production and Editing", u:3}, {c:"WDS", t:"Web Design and Scripting", u:3}, {c:"GDT", t:"Graphic Design and Typography", u:3}, {c:"SD", t:"Sound Design", u:3}, {c:"PPE", t:"Post-Production Effects", u:3}, {c:"MSP", t:"Multimedia Seminar and Portfolio", u:3}, {c:"IMD", t:"Interactive Media Design", u:3}
    ],
    "Bachelor in Elementary Education": [
        {c:"CAL", t:"Child and Adolescent Learners and Learning Principles", u:3}, {c:"TTP", t:"The Teaching Profession", u:3}, {c:"TTC", t:"The Teacher and the Community", u:3}, {c:"FSIE", t:"Foundations of Special and Inclusive Education", u:3}, {c:"FLCT", t:"Facilitating Learner-Centered Teaching", u:3}, {c:"AOL1", t:"Assessment of Learning 1", u:3}, {c:"AOL2", t:"Assessment of Learning 2", u:3}, {c:"TTL1", t:"Technology for Teaching and Learning 1", u:3}, {c:"FS1", t:"Field Study 1", u:3}, {c:"FS2", t:"Field Study 2", u:3}, {c:"PT", t:"Practice Teaching", u:6},
        {c:"ETE", t:"Enhanced Teaching of English", u:3}, {c:"TMM", t:"Teaching Mathematics in Primary Grades", u:3}, {c:"PEP", t:"Pagtuturo ng Edukasyon sa Pagpapakatao", u:3}, {c:"TSS", t:"Teaching Social Studies", u:3}, {c:"TSC", t:"Teaching Science", u:3}, {c:"TMAP", t:"Teaching Music, Arts, PE, and Health", u:3}, {c:"REE", t:"Research in Elementary Education", u:3}
    ],
    "Bachelor in Secondary Education": [
        {c:"CAL", t:"Child and Adolescent Learners and Learning Principles", u:3}, {c:"TTP", t:"The Teaching Profession", u:3}, {c:"TTC", t:"The Teacher and the Community", u:3}, {c:"FSIE", t:"Foundations of Special and Inclusive Education", u:3}, {c:"FLCT", t:"Facilitating Learner-Centered Teaching", u:3}, {c:"AOL1", t:"Assessment of Learning 1", u:3}, {c:"AOL2", t:"Assessment of Learning 2", u:3}, {c:"TTL1", t:"Technology for Teaching and Learning 1", u:3}, {c:"FS1", t:"Field Study 1", u:3}, {c:"FS2", t:"Field Study 2", u:3}, {c:"PT", t:"Practice Teaching", u:6},
        {c:"SOE", t:"Structure of English", u:3}, {c:"MAF", t:"Mythology and Folklore", u:3}, {c:"SPL", t:"Survey of Philippine Literature", u:3}, {c:"LC", t:"Literary Criticism", u:3}, {c:"LLMD", t:"Language Learning Materials Development", u:3},
        {c:"MG", t:"Modern Geometry", u:3}, {c:"LAL", t:"Linear Algebra", u:3}, {c:"AC", t:"Advanced Calculus", u:3}, {c:"TRG", t:"Trigonometry", u:3}, {c:"AA", t:"Abstract Algebra", u:3}, {c:"NT", t:"Number Theory", u:3},
        {c:"ES", t:"Earth Science", u:3}, {c:"MET", t:"Meteorology", u:3}, {c:"ORGC", t:"Organic Chemistry", u:3}, {c:"INOC", t:"Inorganic Chemistry", u:3}, {c:"GAE", t:"Genetics and Evolution", u:3}, {c:"MEC", t:"Mechanics", u:3}, {c:"WAO", t:"Waves and Optics", u:3},
        {c:"AS", t:"Asian Studies", u:3}, {c:"WH", t:"World History", u:3}, {c:"GEO", t:"Geography", u:3}, {c:"MME", t:"Micro/Macroeconomics", u:3}, {c:"SCA", t:"Socio-Cultural Anthropology", u:3}
    ],
    "Bachelor of Early Childhood Education": [
        {c:"CAL", t:"Child and Adolescent Learners and Learning Principles", u:3}, {c:"TTP", t:"The Teaching Profession", u:3}, {c:"TTC", t:"The Teacher and the Community", u:3}, {c:"FSIE", t:"Foundations of Special and Inclusive Education", u:3}, {c:"FLCT", t:"Facilitating Learner-Centered Teaching", u:3}, {c:"AOL1", t:"Assessment of Learning 1", u:3}, {c:"AOL2", t:"Assessment of Learning 2", u:3}, {c:"TTL1", t:"Technology for Teaching and Learning 1", u:3}, {c:"FS1", t:"Field Study 1", u:3}, {c:"FS2", t:"Field Study 2", u:3}, {c:"PT", t:"Practice Teaching", u:6},
        {c:"FECE", t:"Foundations of Early Childhood Education", u:3}, {c:"CAMD", t:"Creative Arts, Music, and Drama", u:3}, {c:"LLD", t:"Language and Literacy Development", u:3}, {c:"SME", t:"Science and Math in Early Childhood", u:3}, {c:"GCB", t:"Guiding Children's Behavior", u:3}, {c:"HSN", t:"Health, Safety, and Nutrition", u:3}, {c:"IEEC", t:"Inclusive Education in Early Childhood", u:3}, {c:"CGD", t:"Child Growth and Development", u:3}
    ]
};

window.populateSubjects = function() {
    const course = document.getElementById('deanCourseSelect').value;
    const presetSelect = document.getElementById('deanSubjectPreset');
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

window.fillSubjectDetails = function() {
    const course = document.getElementById('deanCourseSelect').value;
    const presetIdx = document.getElementById('deanSubjectPreset').value;
    
    if(course && presetIdx !== "" && curriculumData[course] && curriculumData[course][presetIdx]) {
        const data = curriculumData[course][presetIdx];
        document.getElementById('deanCode').value = data.c;
        document.getElementById('deanTitle').value = data.t;
        document.getElementById('deanUnits').value = data.u;
    }
};


// --- 1. CORE AUDIO SYSTEM MATRIX ---
const coreAudioNode = new Audio();
let originalPlaylistQueue = [];
let activePlaylistQueue = [];
let currentQueueIndex = -1;
let isShuffleActive = false;

// Helper to kill music instantly
function stopMusicEngine() {
    if (coreAudioNode) {
        coreAudioNode.pause();
        coreAudioNode.src = '';
    }
    currentQueueIndex = -1;
}

// --- 2. INDEXEDDB PERSISTENCE LAYER ---
const DB_NAME = 'CampusCoreMusicDB';
const STORE_NAME = 'vault_tracks';
let databaseRef = null;

function initMusicDatabase(callback) {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = function(e) {
        const db = e.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
            db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        }
    };
    request.onsuccess = function(e) {
        databaseRef = e.target.result;
        if (callback) callback();
    };
    request.onerror = function(e) { console.error('IndexedDB structural error:', e); };
}

function persistTrackToDB(fileObject) {
    if (!databaseRef) return;
    const transaction = databaseRef.transaction(STORE_NAME, 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    store.add({ name: fileObject.name, binaryData: fileObject });
}

function purgeTrackFromDB(fileName) {
    if (!databaseRef) return;
    const transaction = databaseRef.transaction(STORE_NAME, 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    store.openCursor().onsuccess = function(e) {
        const cursor = e.target.result;
        if (cursor) {
            if (cursor.value.name === fileName) { cursor.delete(); } 
            else { cursor.continue(); }
        }
    };
}

function restoreTracksFromDB() {
    if (!databaseRef) return;
    const transaction = databaseRef.transaction(STORE_NAME, 'readonly');
    const store = transaction.objectStore(STORE_NAME);
    store.getAll().onsuccess = function(e) {
        const tracks = e.target.result || [];
        originalPlaylistQueue = [];
        tracks.forEach(track => {
            const reconstructedUrl = URL.createObjectURL(track.binaryData);
            const cleanTitle = track.name.replace(/\.[^/.]+$/, "");
            originalPlaylistQueue.push({ title: cleanTitle, url: reconstructedUrl, filename: track.name });
        });
        rebuildActiveQueueChain();
        syncPlayerUI();
    };
}

// --- 3. AUDIO ENGINE LOGIC ---
function loadFilesIntoPlaylist(inputNode) {
    if(!inputNode.files || inputNode.files.length === 0) return;
    for(let i=0; i<inputNode.files.length; i++) {
        let file = inputNode.files[i];
        let url = URL.createObjectURL(file);
        const cleanTitle = file.name.replace(/\.[^/.]+$/, "");
        originalPlaylistQueue.push({ title: cleanTitle, url: url, filename: file.name });
        persistTrackToDB(file); 
    }
    rebuildActiveQueueChain();
    syncPlayerUI();
    inputNode.value = ""; 
}

function purgeTrackFromVault(targetUrl) {
    const trackObj = originalPlaylistQueue.find(t => t.url === targetUrl);
    if(trackObj) { purgeTrackFromDB(trackObj.filename); } 
    
    let activeIdx = activePlaylistQueue.findIndex(t => t.url === targetUrl);
    originalPlaylistQueue = originalPlaylistQueue.filter(t => t.url !== targetUrl);
    
    if(activeIdx === currentQueueIndex && currentQueueIndex !== -1) {
        coreAudioNode.pause();
        coreAudioNode.src = '';
        currentQueueIndex = -1;
    }

    rebuildActiveQueueChain();
    if(currentQueueIndex !== -1 && activeIdx < currentQueueIndex) {
        currentQueueIndex--;
    }
    syncPlayerUI();
}

function rebuildActiveQueueChain() {
    if (!isShuffleActive) {
        activePlaylistQueue = [...originalPlaylistQueue];
    } else {
        activePlaylistQueue = [...originalPlaylistQueue];
        for (let i = activePlaylistQueue.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [activePlaylistQueue[i], activePlaylistQueue[j]] = [activePlaylistQueue[j], activePlaylistQueue[i]];
        }
    }
}

// Global UI synchronization engine. Connects the background JS Audio element back to the active page DOM.
function syncPlayerUI() {
    const container = document.getElementById('deckPlaylistTracksContainer');
    const titleEl = document.getElementById('trackDeckMetaTitle');
    const playBtn = document.getElementById('btnMasterDeckPlay');
    const shuffleBtn = document.getElementById('btnDeckShuffle');
    
    if (titleEl) {
        if (currentQueueIndex !== -1 && activePlaylistQueue[currentQueueIndex]) {
            titleEl.innerText = activePlaylistQueue[currentQueueIndex].title;
        } else {
            titleEl.innerText = "No Local File Loaded";
        }
    }
    
    if (playBtn) {
        if (currentQueueIndex !== -1 && !coreAudioNode.paused) {
            playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
        } else {
            playBtn.innerHTML = '<i class="bi bi-play-fill" style="margin-left: 3px;"></i>';
        }
    }

    if (shuffleBtn) {
        if (isShuffleActive) {
            shuffleBtn.classList.add('active');
        } else {
            shuffleBtn.classList.remove('active');
        }
    }

    if(container) {
        container.innerHTML = '';
        if(originalPlaylistQueue.length === 0) {
            container.innerHTML = '<div class="text-center py-3 text-white-50 small">No local files added yet.</div>';
        } else {
            originalPlaylistQueue.forEach((track) => {
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
                    <div class="text-truncate ps-1 small" style="max-width:200px;"><i class="bi bi-music-note me-2 opacity-50"></i>${track.title}</div>
                    <button class="btn btn-sm text-danger btn-purge-track p-1" onclick="purgeTrackFromVault('${track.url}')"><i class="bi bi-trash"></i></button>
                `;
                container.appendChild(node);
            });
        }
    }
}

function fireTrackPlaybackByIndex(targetIdx) {
    if(targetIdx < 0 || targetIdx >= activePlaylistQueue.length) return;
    currentQueueIndex = targetIdx;

    coreAudioNode.src = activePlaylistQueue[currentQueueIndex].url;
    coreAudioNode.play().catch(err => console.log("Playback initialized safely."));
    syncPlayerUI();
}

function toggleDeckPlayback() {
    if(activePlaylistQueue.length === 0) return;
    if(currentQueueIndex === -1) {
        fireTrackPlaybackByIndex(0);
        return;
    }

    if(coreAudioNode.paused) {
        coreAudioNode.play();
    } else {
        coreAudioNode.pause();
    }
    syncPlayerUI();
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
    
    let currentTrackObj = currentQueueIndex !== -1 ? activePlaylistQueue[currentQueueIndex] : null;
    rebuildActiveQueueChain();

    if(currentTrackObj) {
        currentQueueIndex = activePlaylistQueue.findIndex(t => t.url === currentTrackObj.url);
    }
    syncPlayerUI();
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
    if(pane) pane.classList.toggle('d-none');
}

function timeFormatMap(secs) {
    if(isNaN(secs)) return "00:00";
    let m = Math.floor(secs / 60);
    let s = Math.floor(secs % 60);
    return (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
}

coreAudioNode.ontimeupdate = () => {
    if(!coreAudioNode.duration) return;
    
    const elapsed = coreAudioNode.currentTime;
    const duration = coreAudioNode.duration;
    const pct = (elapsed / duration) * 100;

    const deckSeeker = document.getElementById('deckTimelineSeeker');
    if(deckSeeker) {
        deckSeeker.value = pct;
        deckSeeker.style.setProperty('--seek-percent', pct + '%');
    }
    
    const elapsedText = document.getElementById('deckTimeElapsed');
    const remainingText = document.getElementById('deckTimeRemaining');
    if(elapsedText) elapsedText.innerText = timeFormatMap(elapsed);
    if(remainingText) remainingText.innerText = "- " + timeFormatMap(duration - elapsed);
};

coreAudioNode.onended = () => { nextDeckTrack(); };


// --- 4. STUDY WORKSPACE POMODORO TIMER LOGIC ---
let timerEngineRunning = false;
let timerLoopHandle = null;
let currentBaseSeconds = 1500; 
let targetDurationSeconds = 1500; 

function setTimerPreset(mins) {
    currentBaseSeconds = mins * 60;
    document.querySelectorAll('.timer-preset-btn').forEach(btn => btn.classList.remove('active'));
    
    if(mins === 25) { const el = document.getElementById('btnPresetStudy'); if(el) el.classList.add('active'); }
    if(mins === 5)  { const el = document.getElementById('btnPresetShort'); if(el) el.classList.add('active'); }
    if(mins === 15) { const el = document.getElementById('btnPresetLong'); if(el) el.classList.add('active'); }
    
    resetTimerCore();
}

function setCustomTimer() {
    const input = document.getElementById('customTimerInput');
    if(!input) return;
    const mins = parseInt(input.value);
    if (mins && mins > 0) {
        currentBaseSeconds = mins * 60;
        document.querySelectorAll('.timer-preset-btn').forEach(btn => btn.classList.remove('active'));
        resetTimerCore();
        input.value = ''; 
    }
}

function toggleTimerCore() {
    const timerControlBtn = document.getElementById('btnTimerControl');
    
    if(timerEngineRunning) {
        clearInterval(timerLoopHandle);
        timerEngineRunning = false;
        if(timerControlBtn) timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
    } else {
        timerEngineRunning = true;
        if(timerControlBtn) timerControlBtn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>Pause';
        
        timerLoopHandle = setInterval(() => {
            if(targetDurationSeconds <= 0) {
                clearInterval(timerLoopHandle);
                timerEngineRunning = false;
                if(timerControlBtn) timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
                alert("Study timer session complete! Resetting block intervals.");
                resetTimerCore();
                return;
            }
            targetDurationSeconds--;
            
            const clockDisplay = document.getElementById('countdownClockDisplay');
            if(clockDisplay) {
                let mins = Math.floor(targetDurationSeconds / 60);
                let secs = Math.floor(targetDurationSeconds % 60);
                clockDisplay.innerText = (mins < 10 ? "0" : "") + mins + ":" + (secs < 10 ? "0" : "") + secs;
            }
        }, 1000);
    }
}

function resetTimerCore() {
    clearInterval(timerLoopHandle);
    timerEngineRunning = false;
    
    const timerControlBtn = document.getElementById('btnTimerControl');
    if(timerControlBtn) timerControlBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start';
    
    targetDurationSeconds = currentBaseSeconds;

    const clockDisplay = document.getElementById('countdownClockDisplay');
    if(clockDisplay) {
        let mins = Math.floor(targetDurationSeconds / 60);
        let secs = Math.floor(targetDurationSeconds % 60);
        clockDisplay.innerText = (mins < 10 ? "0" : "") + mins + ":" + (secs < 10 ? "0" : "") + secs;
    }
}

// --- 5. SPA LAYOUT ASYNCHRONOUS INTERCEPTOR ---
document.addEventListener('click', function(e) {
    const anchor = e.target.closest('a');
    if (!anchor) return;
    
    const href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    
    if (href.startsWith('?') || href.includes(window.location.pathname)) {
        e.preventDefault();
        
        // INTERCEPT LOGOUT CLICK EXPLICITLY TO KILL AUDIO INSTANTLY
        if (href.includes('action=logout')) {
            stopMusicEngine();
        }
        
        executeSpaNavigation(href);
    }
});

function executeSpaNavigation(targetUrl) {
    fetch(targetUrl)
        .then(res => res.text())
        .then(htmlString => {
            const parser = new DOMParser();
            const foreignDoc = parser.parseFromString(htmlString, 'text/html');
            
            history.pushState(null, '', targetUrl);
            
            const isTargetLoggedOut = !foreignDoc.querySelector('#sidebarMenu');
            const isCurrentLoggedOut = !document.querySelector('#sidebarMenu');
            
            if (isTargetLoggedOut !== isCurrentLoggedOut) {
                // HALT MUSIC IF TRANSITIONING OUT OF SESSION
                if (isTargetLoggedOut) {
                    stopMusicEngine();
                }
                document.body.innerHTML = foreignDoc.body.innerHTML;
            } else {
                if (!isTargetLoggedOut) {
                    const activeContentPanel = document.querySelector('.col-md-10');
                    const foreignContentPanel = foreignDoc.querySelector('.col-md-10');
                    if (activeContentPanel && foreignContentPanel) {
                        activeContentPanel.innerHTML = foreignContentPanel.innerHTML;
                    }
                    
                    const activeSidebar = document.querySelector('#sidebarMenu');
                    const foreignSidebar = foreignDoc.querySelector('#sidebarMenu');
                    if (activeSidebar && foreignSidebar) {
                        activeSidebar.innerHTML = foreignSidebar.innerHTML;
                    }
                } else {
                    const activeLogin = document.querySelector('.login-box')?.parentElement;
                    const foreignLogin = foreignDoc.querySelector('.login-box')?.parentElement;
                    if (activeLogin && foreignLogin) {
                        activeLogin.innerHTML = foreignLogin.innerHTML;
                    }
                }
            }
            bindSpaFormSubmissions();
            syncPlayerUI();
        })
        .catch(err => {
            console.error("SPA Routing disruption:", err);
            window.location.href = targetUrl;
        });
}

function bindSpaFormSubmissions() {
    document.querySelectorAll('form').forEach(form => {
        if (form.dataset.spaIntercepted) return;
        form.dataset.spaIntercepted = "true";
        
        form.addEventListener('submit', function(e) {
            const endpoint = form.getAttribute('action') || window.location.search || '?page=home';
            e.preventDefault();
            
            const payload = new FormData(form);
            if (e.submitter && e.submitter.name) {
                payload.append(e.submitter.name, e.submitter.value);
            }
            
            fetch(endpoint, {
                method: form.getAttribute('method') || 'POST',
                body: form.getAttribute('method')?.toUpperCase() === 'GET' ? null : payload
            })
            .then(res => res.text())
            .then(htmlString => {
                const parser = new DOMParser();
                const foreignDoc = parser.parseFromString(htmlString, 'text/html');
                
                const isTargetLoggedOut = !foreignDoc.querySelector('#sidebarMenu');
                const isCurrentLoggedOut = !document.querySelector('#sidebarMenu');

                if (isTargetLoggedOut !== isCurrentLoggedOut) {
                    // HALT MUSIC IF TRANSITIONING OUT OF SESSION DUE TO FORM BEHAVIOR (e.g. Session Expiry)
                    if (isTargetLoggedOut) {
                        stopMusicEngine();
                    }
                    document.body.innerHTML = foreignDoc.body.innerHTML;
                } else {
                    if (!isTargetLoggedOut) {
                        const activeContentPanel = document.querySelector('.col-md-10');
                        const foreignContentPanel = foreignDoc.querySelector('.col-md-10');
                        if (activeContentPanel && foreignContentPanel) {
                            activeContentPanel.innerHTML = foreignContentPanel.innerHTML;
                        }
                    } else {
                        const activeLogin = document.querySelector('.login-box')?.parentElement;
                        const foreignLogin = foreignDoc.querySelector('.login-box')?.parentElement;
                        if (activeLogin && foreignLogin) {
                            activeLogin.innerHTML = foreignLogin.innerHTML;
                        }
                    }
                }

                // Handle bootstrap modals manually removing backdrop overlays
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(b => b.remove());
                document.body.classList.remove('modal-open');
                document.body.style = '';

                bindSpaFormSubmissions();
                syncPlayerUI();
            })
            .catch(err => {
                form.submit(); // fallback
            });
        });
    });
}

// System Boot Initialization Sequence
document.addEventListener('DOMContentLoaded', () => {
    initMusicDatabase(restoreTracksFromDB);
    bindSpaFormSubmissions();
});
</script>
			<script>

const player = document.getElementById('sharedPlayer');
const songInput = document.getElementById('songInput');

function loadSong() {

    if (!player || !songInput) return;

    const url = songInput.value;

    if (!url) {
        alert('Please paste an MP3 URL');
        return;
    }

    player.src = url;

    player.play();

    syncMusic();
}

async function syncMusic() {

    if (!player) return;

    const formData = new FormData();

    formData.append('sync_music', 1);
    formData.append('song', player.src);
    formData.append('time', player.currentTime);
    formData.append('playing', !player.paused ? 1 : 0);

    try {

        await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

    } catch(err) {

        console.log(err);
    }
}

if (player) {

    player.addEventListener('play', syncMusic);
    player.addEventListener('pause', syncMusic);

    setInterval(syncMusic, 1000);
}

async function fetchMusicState() {

    if (!player) return;

    try {

        const response = await fetch('?get_music=1');

        const data = await response.json();

        if (!data) return;

        if (data.song_url && player.src !== data.song_url) {

            player.src = data.song_url;
        }

        if (
            data.song_time &&
            Math.abs(player.currentTime - data.song_time) > 2
        ) {

            player.currentTime = data.song_time;
        }

        if (data.is_playing === true || data.is_playing == 1) {

            if (player.paused) {
                player.play();
            }

        } else {

            if (!player.paused) {
                player.pause();
            }
        }

    } catch(err) {

        console.log(err);
    }
}

setInterval(fetchMusicState, 1000);

</script>
</body>
</html>
