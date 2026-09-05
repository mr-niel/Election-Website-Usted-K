<?php
/**
 * API 3 — Results & Admin Management
 * ==================================
 *
 * PUBLIC
 *   GET  ?action=results          -> live tally (optionally ?election_id=)
 *   GET  ?action=turnout          -> turnout stats (optionally ?election_id=)
 *   GET  ?action=elections        -> list all elections
 *
 * ADMIN (session role must be admin)
 *   GET  ?action=admin_elections  -> list all elections
 *   POST ?action=add_election     -> { title, type, campus_id, department_id?, hall_id?, start_time, end_time }
 *   POST ?action=open_election    -> { election_id }
 *   POST ?action=close_election   -> { election_id }
 *   GET  ?action=admin_positions  -> positions + candidates (optionally ?election_id=)
 *   POST ?action=add_position     -> { election_id, title, description, max_votes, display_order }
 *   POST ?action=add_candidate    -> { position_id, full_name, student_id, department_id, manifesto, photo_url }
 *   GET  ?action=pending_voters   -> voters awaiting approval
 *   POST ?action=approve_voter    -> { voter_id }
 *   POST ?action=reject_voter     -> { voter_id }
 *   GET  ?action=admin_stats      -> dashboard counts
 */

session_start();
require __DIR__ . '/db.php';

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        json_response(['error' => 'Admin login required.'], 401);
    }
}

function fetch_election(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT e.*, c.name AS campus_name,
                d.name AS department_name, h.name AS hall_name
         FROM elections e
         JOIN campuses c ON c.id = e.campus_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN halls h ON h.id = e.hall_id
         WHERE e.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ============================================================
        //  PUBLIC
        // ============================================================

        case 'results':
            $election_id = (int)($_GET['election_id'] ?? 0);
            if ($election_id > 0) {
                $election = fetch_election($election_id);
                if (!$election) json_response(['error' => 'Election not found.'], 404);
                $elections = [$election];
            } else {
                $elections = db()->query(
                    'SELECT e.*, c.name AS campus_name,
                            d.name AS department_name, h.name AS hall_name
                     FROM elections e
                     JOIN campuses c ON c.id = e.campus_id
                     LEFT JOIN departments d ON d.id = e.department_id
                     LEFT JOIN halls h ON h.id = e.hall_id
                     ORDER BY e.type, e.id'
                )->fetchAll();
            }

            $out = [];
            foreach ($elections as $e) {
                $stmt = db()->prepare(
                    'SELECT id, title, description, display_order
                     FROM positions WHERE election_id = ? ORDER BY display_order, id'
                );
                $stmt->execute([$e['id']]);
                $positions = $stmt->fetchAll();

                $posOut = [];
                foreach ($positions as $pos) {
                    $stmt = db()->prepare(
                        'SELECT c.id, c.full_name, c.student_id, c.manifesto, c.photo_url,
                                d.name AS department,
                                (SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id) AS votes
                         FROM candidates c
                         JOIN departments d ON d.id = c.department_id
                         WHERE c.position_id = ?
                         ORDER BY votes DESC, c.full_name'
                    );
                    $stmt->execute([$pos['id']]);
                    $cands = $stmt->fetchAll();
                    foreach ($cands as &$c) {
                        $c['votes'] = (int)$c['votes'];
                    }
                    $pos['candidates'] = $cands;
                    $posOut[] = $pos;
                }

                $totalVotes = (int) db()->query(
                    'SELECT COUNT(*) FROM votes v JOIN positions p ON p.id = v.position_id WHERE p.election_id = ' . (int)$e['id']
                )->fetchColumn();

                $out[] = [
                    'id'           => (int)$e['id'],
                    'title'        => $e['title'],
                    'type'         => $e['type'],
                    'status'       => $e['status'],
                    'campus_name'  => $e['campus_name'],
                    'department_name' => $e['department_name'],
                    'hall_name'    => $e['hall_name'],
                    'positions'    => $posOut,
                    'total_votes'  => $totalVotes,
                ];
            }

            json_response(['elections' => $out]);
            break;

        case 'turnout':
            $election_id = (int)($_GET['election_id'] ?? 0);
            $approved = (int) db()->query("SELECT COUNT(*) FROM voters WHERE status='approved'")->fetchColumn();
            $voted    = (int) db()->query('SELECT COUNT(*) FROM voters WHERE has_voted_src=1')->fetchColumn();
            $pct      = $approved > 0 ? round(($voted / $approved) * 100, 1) : 0;
            $maleApproved   = (int) db()->query("SELECT COUNT(*) FROM voters WHERE status='approved' AND gender='male'")->fetchColumn();
            $femaleApproved = (int) db()->query("SELECT COUNT(*) FROM voters WHERE status='approved' AND gender='female'")->fetchColumn();
            $maleVoted      = (int) db()->query("SELECT COUNT(*) FROM voters WHERE has_voted_src=1 AND gender='male'")->fetchColumn();
            $femaleVoted    = (int) db()->query("SELECT COUNT(*) FROM voters WHERE has_voted_src=1 AND gender='female'")->fetchColumn();

            // campus breakdown
            $campusBreakdown = db()->query(
                "SELECT c.name AS campus,
                        SUM(CASE WHEN v.status='approved' THEN 1 ELSE 0 END) AS approved,
                        SUM(CASE WHEN v.has_voted_src=1 THEN 1 ELSE 0 END) AS voted
                 FROM voters v
                 JOIN campuses c ON c.id = v.campus_id
                 GROUP BY c.id
                 ORDER BY c.name"
            )->fetchAll();

            json_response([
                'approved_voters' => $approved,
                'voted'           => $voted,
                'turnout_percent' => $pct,
                'male_approved'   => $maleApproved,
                'female_approved' => $femaleApproved,
                'male_voted'      => $maleVoted,
                'female_voted'    => $femaleVoted,
                'campus_breakdown'=> $campusBreakdown,
            ]);
            break;

        case 'elections':
            $rows = db()->query(
                'SELECT e.id, e.title, e.type, e.status, e.start_time, e.end_time,
                        c.name AS campus_name,
                        d.name AS department_name, h.name AS hall_name
                 FROM elections e
                 JOIN campuses c ON c.id = e.campus_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 LEFT JOIN halls h ON h.id = e.hall_id
                 ORDER BY e.type, e.id'
            )->fetchAll();
            json_response(['elections' => $rows]);
            break;

        // ============================================================
        //  ADMIN
        // ============================================================

        case 'admin_elections':
            require_admin();
            $rows = db()->query(
                'SELECT e.*, c.name AS campus_name,
                        d.name AS department_name, h.name AS hall_name
                 FROM elections e
                 JOIN campuses c ON c.id = e.campus_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 LEFT JOIN halls h ON h.id = e.hall_id
                 ORDER BY e.type, e.id'
            )->fetchAll();
            json_response(['elections' => $rows]);
            break;

        case 'add_election':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $title       = trim($b['title'] ?? '');
            $type        = $b['type'] ?? 'src';
            $campus_id   = (int)($b['campus_id'] ?? 0);
            $dept_id     = (int)($b['department_id'] ?? 0);
            $hall_id     = (int)($b['hall_id'] ?? 0);
            $start_time  = trim($b['start_time'] ?? '');
            $end_time    = trim($b['end_time'] ?? '');

            if ($title === '' || $campus_id <= 0 || $start_time === '' || $end_time === '') {
                json_response(['error' => 'Title, campus, start time, and end time are required.'], 422);
            }
            if (!in_array($type, ['src', 'departmental', 'hall', 'class'], true)) {
                json_response(['error' => 'Invalid election type.'], 422);
            }
            if ($type === 'departmental' && $dept_id <= 0) {
                json_response(['error' => 'Departmental elections require a department.'], 422);
            }
            if ($type === 'hall' && $hall_id <= 0) {
                json_response(['error' => 'Hall elections require a hall.'], 422);
            }

            $stmt = db()->prepare(
                'INSERT INTO elections (title, type, campus_id, department_id, hall_id, start_time, end_time, status)
                 VALUES (?,?,?,?,?,?,?,"setup")'
            );
            $stmt->execute([
                $title, $type, $campus_id,
                $type === 'departmental' || $type === 'class' ? $dept_id : null,
                $type === 'hall' ? $hall_id : null,
                $start_time, $end_time,
            ]);
            json_response(['message' => 'Election created.', 'id' => (int)db()->lastInsertId()], 201);
            break;

        case 'open_election':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $id = (int)($b['election_id'] ?? 0);
            if ($id <= 0) json_response(['error' => 'Invalid election id.'], 422);
            db()->prepare("UPDATE elections SET status='open' WHERE id=?")->execute([$id]);
            json_response(['message' => 'Election opened.']);
            break;

        case 'close_election':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $id = (int)($b['election_id'] ?? 0);
            if ($id <= 0) json_response(['error' => 'Invalid election id.'], 422);
            db()->prepare("UPDATE elections SET status='closed' WHERE id=?")->execute([$id]);
            json_response(['message' => 'Election closed.']);
            break;

        case 'admin_positions':
            require_admin();
            $election_id = (int)($_GET['election_id'] ?? 0);
            if ($election_id > 0) {
                $stmt = db()->prepare(
                    'SELECT id, title, description, max_votes, display_order
                     FROM positions WHERE election_id = ? ORDER BY display_order, id'
                );
                $stmt->execute([$election_id]);
            } else {
                $stmt = db()->query(
                    'SELECT p.id, p.title, p.description, p.max_votes, p.display_order, p.election_id,
                            e.title AS election_title
                     FROM positions p
                     JOIN elections e ON e.id = p.election_id
                     ORDER BY e.type, p.display_order, p.id'
                );
            }
            $positions = $stmt->fetchAll();
            foreach ($positions as &$pos) {
                $stmt = db()->prepare(
                    'SELECT c.id, c.full_name, c.student_id, c.manifesto, c.photo_url,
                            d.name AS department
                     FROM candidates c
                     JOIN departments d ON d.id = c.department_id
                     WHERE c.position_id = ? ORDER BY c.id'
                );
                $stmt->execute([$pos['id']]);
                $pos['candidates'] = $stmt->fetchAll();
            }
            json_response(['positions' => $positions]);
            break;

        case 'add_position':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $election_id = (int)($b['election_id'] ?? 0);
            $title       = trim($b['title'] ?? '');
            $description = trim($b['description'] ?? '');
            $max_votes   = (int)($b['max_votes'] ?? 1);
            $order       = (int)($b['display_order'] ?? 0);
            if ($election_id <= 0) json_response(['error' => 'Election is required.'], 422);
            if ($title === '') json_response(['error' => 'Title is required.'], 422);

            // verify election exists
            $stmt = db()->prepare('SELECT id FROM elections WHERE id = ?');
            $stmt->execute([$election_id]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Election not found.'], 422);
            }

            $stmt = db()->prepare(
                'INSERT INTO positions (election_id, title, description, max_votes, display_order)
                 VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$election_id, $title, $description, max(1, $max_votes), $order]);
            json_response(['message' => 'Position added.', 'id' => (int)db()->lastInsertId()], 201);
            break;

        case 'add_candidate':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $position_id   = (int)($b['position_id'] ?? 0);
            $full_name     = trim($b['full_name'] ?? '');
            $student_id    = trim($b['student_id'] ?? '');
            $department_id = (int)($b['department_id'] ?? 0);
            $manifesto     = trim($b['manifesto'] ?? '');
            $photo_url     = trim($b['photo_url'] ?? '');
            if ($position_id <= 0 || $full_name === '' || $student_id === '' || $department_id <= 0) {
                json_response(['error' => 'Position, candidate name, student ID, and department are required.'], 422);
            }
            $stmt = db()->prepare('SELECT id FROM departments WHERE id = ?');
            $stmt->execute([$department_id]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Invalid department.'], 422);
            }
            $stmt = db()->prepare(
                'INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto, photo_url)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$position_id, $full_name, $student_id, $department_id, $manifesto, $photo_url ?: null]);
            json_response(['message' => 'Candidate added.', 'id' => (int)db()->lastInsertId()], 201);
            break;

        case 'populate_positions':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $election_id = (int)($b['election_id'] ?? 0);
            if ($election_id <= 0) json_response(['error' => 'Election is required.'], 422);

            $stmt = db()->prepare('SELECT id, type FROM elections WHERE id = ?');
            $stmt->execute([$election_id]);
            $election = $stmt->fetch();
            if (!$election) json_response(['error' => 'Election not found.'], 422);

            $standard = [
                'src' => [
                    ['President', 'Head of the student body and chief spokesperson.'],
                    ['Vice President', 'Assists the president and oversees assigned coordination.'],
                    ['General Secretary', 'Handles official correspondence, minutes, and records.'],
                    ['Deputy General Secretary', 'Supports administrative secretarial duties.'],
                    ['Financial Secretary', 'Manages financial records and budget proposals.'],
                    ['Treasurer', 'Custodian of day-to-day finances and cash flows.'],
                    ['Organizing Secretary', 'Coordinates campus-wide student events, programs, and logistics.'],
                    ['Public Relations Officer (PRO)', 'Manages information flow, media, and public relations.'],
                    ["Women\u2019s Commissioner", 'Advocates for female student welfare and organizes gender-specific programs.'],
                    ['Sports Commissioner', 'Oversees sports, games, and male student welfare matters.'],
                ],
                'departmental' => [
                    ['President', 'Leads the departmental student association.'],
                    ['Vice President', 'Deputizes for the departmental president.'],
                    ['Secretary', 'Takes records and manages correspondence for the department.'],
                    ['Financial Secretary / Treasurer', 'Handles association dues and program funds.'],
                    ['Organizing Secretary', 'Plans academic quizzes, excursions, and department-specific seminars.'],
                    ['Public Relations Officer (PRO)', 'Communicates updates between faculty, department heads, and students.'],
                ],
                'hall' => [
                    ['JCRC President', 'Head of the hall of residence student leadership.'],
                    ['JCRC Vice President', 'Supports hall governance and internal management.'],
                    ['JCRC Secretary', 'Manages hall meetings and documentation.'],
                    ['Financial Secretary / Treasurer', 'Oversees hall dues and JCRC account administration.'],
                    ['Organizing Secretary', 'Coordinates hall week celebrations, clean-up exercises, and social gatherings.'],
                    ['Entertainment / Welfare Committee Head', 'Manages recreational activities and room allocation / student comfort issues within the hall.'],
                ],
                'class' => [
                    ['Class Representative', 'Represents the class in departmental and faculty matters.'],
                    ['Assistant Class Representative', 'Supports the class representative and assumes duties in their absence.'],
                ],
            ];

            $type = $election['type'];
            if (!isset($standard[$type])) {
                json_response(['error' => 'No standard positions defined for this election type.'], 422);
            }

            $existing = db()->prepare('SELECT COUNT(*) FROM positions WHERE election_id = ?');
            $existing->execute([$election_id]);
            if ((int)$existing->fetchColumn() > 0) {
                json_response(['error' => 'This election already has positions. Remove them first if you want to auto-populate.'], 422);
            }

            $insert = db()->prepare(
                'INSERT INTO positions (election_id, title, description, max_votes, display_order)
                 VALUES (?,?,?,?,?)'
            );
            $order = 1;
            foreach ($standard[$type] as [$title, $desc]) {
                $insert->execute([$election_id, $title, $desc, 1, $order]);
                $order++;
            }
            json_response(['message' => count($standard[$type]) . ' standard positions added for ' . strtoupper($type) . ' election.'], 201);
            break;

        case 'upload_candidate_photo':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $candidate_id = (int)($_POST['candidate_id'] ?? 0);
            if ($candidate_id <= 0) json_response(['error' => 'Invalid candidate id.'], 422);
            if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                json_response(['error' => 'No file uploaded or upload failed.'], 422);
            }
            $file = $_FILES['photo'];
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
            $uploadDir = __DIR__ . '/../public/candidate-photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = $allowedTypes[$mime];
            $filename = 'candidate_' . $candidate_id . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                json_response(['error' => 'Failed to save image.'], 500);
            }
            $photoUrl = 'public/candidate-photos/' . $filename;
            db()->prepare('UPDATE candidates SET photo_url = ? WHERE id = ?')->execute([$photoUrl, $candidate_id]);
            json_response(['message' => 'Photo uploaded.', 'photo_url' => $photoUrl]);
            break;

        case 'pending_voters':
            require_admin();
            $rows = db()->query(
                "SELECT v.id, v.student_id, v.full_name, v.email, v.phone, v.level, v.gender,
                        v.id_photo_url, v.otp_verified, v.status,
                        c.name AS campus, d.name AS department, p.name AS programme,
                        h.name AS hall, v.created_at
                 FROM voters v
                 JOIN campuses c ON c.id = v.campus_id
                 JOIN departments d ON d.id = v.department_id
                 JOIN programmes p ON p.id = v.programme_id
                 LEFT JOIN halls h ON h.id = v.hall_id
                 ORDER BY v.created_at DESC
                 LIMIT 50"
            )->fetchAll();
            json_response(['voters' => $rows]);
            break;

        case 'approve_voter':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $id = (int)($b['voter_id'] ?? 0);
            if ($id <= 0) json_response(['error' => 'Invalid voter id.'], 422);
            db()->prepare("UPDATE voters SET status='approved' WHERE id=?")->execute([$id]);
            json_response(['message' => 'Voter approved.']);
            break;

        case 'reject_voter':
            require_admin();
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $b = read_json_body();
            $id = (int)($b['voter_id'] ?? 0);
            if ($id <= 0) json_response(['error' => 'Invalid voter id.'], 422);
            db()->prepare("UPDATE voters SET status='rejected' WHERE id=?")->execute([$id]);
            json_response(['message' => 'Voter rejected.']);
            break;

        case 'admin_stats':
            require_admin();
            $stats = [
                'voters_total'    => (int) db()->query('SELECT COUNT(*) FROM voters')->fetchColumn(),
                'voters_pending'  => (int) db()->query("SELECT COUNT(*) FROM voters WHERE status='pending'")->fetchColumn(),
                'voters_approved' => (int) db()->query("SELECT COUNT(*) FROM voters WHERE status='approved'")->fetchColumn(),
                'voters_voted'    => (int) db()->query('SELECT COUNT(*) FROM voters WHERE has_voted_src=1')->fetchColumn(),
                'positions'       => (int) db()->query('SELECT COUNT(*) FROM positions')->fetchColumn(),
                'candidates'      => (int) db()->query('SELECT COUNT(*) FROM candidates')->fetchColumn(),
                'votes_total'     => (int) db()->query('SELECT COUNT(*) FROM votes')->fetchColumn(),
                'elections'       => (int) db()->query('SELECT COUNT(*) FROM elections')->fetchColumn(),
            ];
            $elections = db()->query(
                'SELECT e.id, e.title, e.type, e.status, e.start_time, e.end_time,
                        c.name AS campus_name
                 FROM elections e
                 JOIN campuses c ON c.id = e.campus_id
                 ORDER BY e.type, e.id'
            )->fetchAll();
            $stats['elections_list'] = $elections;
            json_response($stats);
            break;

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
}
