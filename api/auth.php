<?php
/**
 * API 1 — Authentication & Registration
 * ======================================
 *
 * Endpoints (use ?action= on the URL):
 *   POST ?action=register           -> voter creates account (status=pending, needs ID + OTP)
 *   POST ?action=upload_id_photo    -> upload student ID card photo (multipart form)
 *   POST ?action=send_otp           -> generate + send OTP via SMS to voter's phone
 *   POST ?action=verify_otp         -> verify OTP code, set otp_verified=1
 *   POST ?action=login              -> voter login (requires otp_verified=1 + status=approved)
 *   POST ?action=admin_login        -> admin login
 *   GET  ?action=me                 -> current session info
 *   POST ?action=logout             -> destroy session
 *   GET  ?action=campuses           -> list campuses
 *   GET  ?action=departments        -> list departments (optionally ?campus_id=)
 *   GET  ?action=programmes         -> list programmes (optionally ?department_id=)
 *   GET  ?action=halls              -> list halls (optionally ?campus_id=)
 */

session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/sms.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ----------------------------------------------------
        //  Voter registration
        // ----------------------------------------------------
        case 'register':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $b = read_json_body();
            $student_id   = trim($b['student_id'] ?? '');
            $full_name    = trim($b['full_name'] ?? '');
            $email        = trim($b['email'] ?? '');
            $password     = $b['password'] ?? '';
            $phone        = trim($b['phone'] ?? '');
            $campus_id    = (int)($b['campus_id'] ?? 0);
            $dept_id      = (int)($b['department_id'] ?? 0);
            $prog_id      = (int)($b['programme_id'] ?? 0);
            $hall_id      = (int)($b['hall_id'] ?? 0);
            $level        = $b['level'] ?? '100';
            $gender       = $b['gender'] ?? 'male';

            if ($student_id === '' || $full_name === '' || $email === '' || strlen($password) < 6) {
                json_response(['error' => 'All fields are required and password must be 6+ characters.'], 422);
            }
            if ($phone === '') {
                json_response(['error' => 'Phone number is required for OTP verification.'], 422);
            }
            $phoneNormalized = normalize_ghana_phone($phone);
            if ($phoneNormalized === null) {
                json_response(['error' => 'Invalid phone number. Use format: 0241234567 or +233241234567'], 422);
            }
            if (!in_array($level, ['100', '200', '300', '400'], true)) {
                json_response(['error' => 'Invalid level.'], 422);
            }
            if (!in_array($gender, ['male', 'female'], true)) {
                json_response(['error' => 'Invalid gender.'], 422);
            }

            // uniqueness check
            $stmt = db()->prepare('SELECT id FROM voters WHERE student_id = ? OR email = ? LIMIT 1');
            $stmt->execute([$student_id, $email]);
            if ($stmt->fetch()) {
                json_response(['error' => 'A voter with that student ID or email already exists.'], 409);
            }

            // validate campus
            $stmt = db()->prepare('SELECT id FROM campuses WHERE id = ?');
            $stmt->execute([$campus_id]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Invalid campus.'], 422);
            }

            // validate department belongs to campus
            $stmt = db()->prepare('SELECT id FROM departments WHERE id = ? AND campus_id = ?');
            $stmt->execute([$dept_id, $campus_id]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Invalid department for that campus.'], 422);
            }

            // validate programme belongs to department
            $stmt = db()->prepare('SELECT id FROM programmes WHERE id = ? AND department_id = ?');
            $stmt->execute([$prog_id, $dept_id]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Invalid programme for that department.'], 422);
            }

            // validate hall if provided
            if ($hall_id > 0) {
                $stmt = db()->prepare('SELECT id FROM halls WHERE id = ? AND campus_id = ?');
                $stmt->execute([$hall_id, $campus_id]);
                if (!$stmt->fetch()) {
                    json_response(['error' => 'Invalid hall for that campus.'], 422);
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare(
                'INSERT INTO voters (student_id, full_name, email, password_hash, phone, campus_id, department_id, programme_id, hall_id, level, gender, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, "pending")'
            );
            $stmt->execute([$student_id, $full_name, $email, $hash, $phoneNormalized, $campus_id, $dept_id, $prog_id, $hall_id > 0 ? $hall_id : null, $level, $gender]);

            $voterId = (int)db()->lastInsertId();
            json_response([
                'message'  => 'Account created. Next: upload your student ID photo and verify your phone with an OTP code.',
                'voter_id' => $voterId,
            ], 201);
            break;

        // ----------------------------------------------------
        //  Upload student ID photo
        // ----------------------------------------------------
        case 'upload_id_photo':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $voter_id = (int)($_POST['voter_id'] ?? 0);
            if ($voter_id <= 0) {
                json_response(['error' => 'Voter ID is required.'], 422);
            }
            if (empty($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
                json_response(['error' => 'No file uploaded or upload failed.'], 422);
            }
            $file = $_FILES['id_photo'];
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowedTypes[$mime])) {
                json_response(['error' => 'Only JPG, PNG, and WebP images are allowed.'], 422);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                json_response(['error' => 'Image must be 5 MB or smaller.'], 422);
            }
            $uploadDir = __DIR__ . '/../public/id-photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = $allowedTypes[$mime];
            $filename = 'voter_' . $voter_id . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                json_response(['error' => 'Failed to save image.'], 500);
            }
            $photoUrl = 'public/id-photos/' . $filename;
            db()->prepare('UPDATE voters SET id_photo_url = ? WHERE id = ?')->execute([$photoUrl, $voter_id]);
            json_response(['message' => 'ID photo uploaded.', 'id_photo_url' => $photoUrl]);
            break;

        // ----------------------------------------------------
        //  Send OTP to voter's phone
        // ----------------------------------------------------
        case 'send_otp':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $b = read_json_body();
            $voter_id = (int)($b['voter_id'] ?? 0);
            if ($voter_id <= 0) {
                json_response(['error' => 'Voter ID is required.'], 422);
            }
            $stmt = db()->prepare('SELECT id, phone, full_name FROM voters WHERE id = ? LIMIT 1');
            $stmt->execute([$voter_id]);
            $voter = $stmt->fetch();
            if (!$voter) {
                json_response(['error' => 'Voter not found.'], 404);
            }
            if (empty($voter['phone'])) {
                json_response(['error' => 'No phone number on file. Please re-register with a phone number.'], 422);
            }

            // rate limit: don't allow more than 1 OTP per 60 seconds
            $stmt = db()->prepare('SELECT otp_code, otp_expires FROM voters WHERE id = ?');
            $stmt->execute([$voter_id]);
            $row = $stmt->fetch();
            if ($row && $row['otp_expires']) {
                $lastSent = strtotime($row['otp_expires']);
                if ($lastSent && (time() - $lastSent) < 300) {
                    // still within 5-min window, resend same code
                    $code = $row['otp_code'];
                } else {
                    $code = generate_otp();
                }
            } else {
                $code = generate_otp();
            }

            $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes
            db()->prepare('UPDATE voters SET otp_code = ?, otp_expires = ? WHERE id = ?')
                ->execute([$code, $expires, $voter_id]);

            $result = send_otp($voter['phone'], $code);

            if (!$result['success']) {
                json_response(['error' => $result['message']], 500);
            }

            json_response([
                'message'   => 'OTP sent to ' . $voter['phone'] . '.',
                'phone'     => $voter['phone'],
            ]);
            break;

        // ----------------------------------------------------
        //  Verify OTP code
        // ----------------------------------------------------
        case 'verify_otp':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $b = read_json_body();
            $voter_id = (int)($b['voter_id'] ?? 0);
            $code     = trim($b['code'] ?? '');
            if ($voter_id <= 0 || $code === '') {
                json_response(['error' => 'Voter ID and OTP code are required.'], 422);
            }

            $stmt = db()->prepare('SELECT id, otp_code, otp_expires, otp_verified FROM voters WHERE id = ? LIMIT 1');
            $stmt->execute([$voter_id]);
            $voter = $stmt->fetch();
            if (!$voter) {
                json_response(['error' => 'Voter not found.'], 404);
            }
            if ($voter['otp_verified']) {
                json_response(['message' => 'Phone already verified.']);
            }
            if (empty($voter['otp_code']) || empty($voter['otp_expires'])) {
                json_response(['error' => 'No OTP has been sent. Request a new code first.'], 422);
            }
            if (strtotime($voter['otp_expires']) < time()) {
                json_response(['error' => 'OTP has expired. Request a new code.'], 422);
            }
            if (!hash_equals($voter['otp_code'], $code)) {
                json_response(['error' => 'Incorrect OTP code. Please try again.'], 401);
            }

            db()->prepare('UPDATE voters SET otp_verified = 1, otp_code = NULL, otp_expires = NULL WHERE id = ?')
                ->execute([$voter_id]);

            json_response(['message' => 'Phone number verified successfully. Your account is now awaiting officer approval.']);
            break;

        // ----------------------------------------------------
        //  Voter login
        // ----------------------------------------------------
        case 'login':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $b = read_json_body();
            $student_id = trim($b['student_id'] ?? '');
            $password   = $b['password'] ?? '';

            $stmt = db()->prepare(
                'SELECT v.id, v.student_id, v.full_name, v.email, v.password_hash,
                        v.phone, v.id_photo_url, v.otp_verified,
                        v.status, v.has_voted_src, v.has_voted_dept, v.has_voted_hall, v.has_voted_class,
                        v.level, v.gender,
                        v.campus_id, c.name AS campus,
                        v.department_id, d.name AS department,
                        v.programme_id, p.name AS programme,
                        v.hall_id, h.name AS hall
                 FROM voters v
                 JOIN campuses c ON c.id = v.campus_id
                 JOIN departments d ON d.id = v.department_id
                 JOIN programmes p ON p.id = v.programme_id
                 LEFT JOIN halls h ON h.id = v.hall_id
                 WHERE v.student_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$student_id]);
            $voter = $stmt->fetch();

            if (!$voter || !password_verify($password, $voter['password_hash'])) {
                json_response(['error' => 'Invalid student ID or password.'], 401);
            }
            if (!$voter['otp_verified']) {
                json_response(['error' => 'Please verify your phone number with an OTP code before logging in.', 'needs_otp' => true, 'voter_id' => (int)$voter['id']], 403);
            }
            if ($voter['status'] === 'pending') {
                json_response(['error' => 'Your account is pending officer approval. You will be notified once approved.', 'needs_approval' => true], 403);
            }
            if ($voter['status'] === 'rejected') {
                json_response(['error' => 'Your account was rejected. Contact the election office.'], 403);
            }

            $_SESSION['voter_id']   = (int)$voter['id'];
            $_SESSION['voter_name'] = $voter['full_name'];

            unset($voter['password_hash']);
            json_response(['message' => 'Login successful.', 'voter' => $voter]);
            break;

        // ----------------------------------------------------
        //  Admin login
        // ----------------------------------------------------
        case 'admin_login':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $b = read_json_body();
            $username = trim($b['username'] ?? '');
            $password = $b['password'] ?? '';

            $stmt = db()->prepare('SELECT id, username, full_name, password_hash FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                json_response(['error' => 'Invalid admin credentials.'], 401);
            }

            $_SESSION['admin_id']   = (int)$admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];

            unset($admin['password_hash']);
            json_response(['message' => 'Admin login successful.', 'admin' => $admin]);
            break;

        // ----------------------------------------------------
        //  Current session
        // ----------------------------------------------------
        case 'me':
            $info = ['authenticated' => false];
            if (!empty($_SESSION['voter_id'])) {
                $stmt = db()->prepare(
                    'SELECT v.id, v.student_id, v.full_name, v.email, v.status, v.phone,
                            v.id_photo_url, v.otp_verified,
                            v.has_voted_src, v.has_voted_dept, v.has_voted_hall, v.has_voted_class,
                            v.level, v.gender,
                            v.campus_id, c.name AS campus,
                            v.department_id, d.name AS department,
                            v.programme_id, p.name AS programme,
                            v.hall_id, h.name AS hall
                     FROM voters v
                     JOIN campuses c ON c.id = v.campus_id
                     JOIN departments d ON d.id = v.department_id
                     JOIN programmes p ON p.id = v.programme_id
                     LEFT JOIN halls h ON h.id = v.hall_id
                     WHERE v.id = ?'
                );
                $stmt->execute([$_SESSION['voter_id']]);
                $v = $stmt->fetch();
                if ($v) {
                    $info = ['authenticated' => true, 'role' => 'voter', 'voter' => $v];
                }
            } elseif (!empty($_SESSION['admin_id'])) {
                $info = [
                    'authenticated' => true,
                    'role'          => 'admin',
                    'admin'         => [
                        'id'       => $_SESSION['admin_id'],
                        'username' => $_SESSION['admin_user'],
                        'full_name'=> $_SESSION['admin_name'],
                    ],
                ];
            }
            json_response($info);
            break;

        // ----------------------------------------------------
        //  Logout
        // ----------------------------------------------------
        case 'logout':
            $_SESSION = [];
            session_destroy();
            json_response(['message' => 'Logged out.']);
            break;

        // ----------------------------------------------------
        //  Dropdown data
        // ----------------------------------------------------
        case 'campuses':
            $rows = db()->query('SELECT id, name, code FROM campuses ORDER BY id')->fetchAll();
            json_response(['campuses' => $rows]);
            break;

        case 'departments':
            $campus_id = (int)($_GET['campus_id'] ?? 0);
            if ($campus_id > 0) {
                $stmt = db()->prepare('SELECT id, name, code FROM departments WHERE campus_id = ? ORDER BY name');
                $stmt->execute([$campus_id]);
            } else {
                $stmt = db()->query('SELECT id, name, code, campus_id FROM departments ORDER BY name');
            }
            $rows = $stmt->fetchAll();
            json_response(['departments' => $rows]);
            break;

        case 'programmes':
            $dept_id = (int)($_GET['department_id'] ?? 0);
            if ($dept_id > 0) {
                $stmt = db()->prepare('SELECT id, name FROM programmes WHERE department_id = ? ORDER BY name');
                $stmt->execute([$dept_id]);
            } else {
                $stmt = db()->query('SELECT id, name, department_id FROM programmes ORDER BY name');
            }
            $rows = $stmt->fetchAll();
            json_response(['programmes' => $rows]);
            break;

        case 'halls':
            $campus_id = (int)($_GET['campus_id'] ?? 0);
            if ($campus_id > 0) {
                $stmt = db()->prepare('SELECT id, name FROM halls WHERE campus_id = ? ORDER BY name');
                $stmt->execute([$campus_id]);
            } else {
                $stmt = db()->query('SELECT id, name, campus_id FROM halls ORDER BY name');
            }
            $rows = $stmt->fetchAll();
            json_response(['halls' => $rows]);
            break;

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
}
