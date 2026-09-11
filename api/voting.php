<?php
/**
 * API 2 — Voting
 * ==============
 *
 * Endpoints (use ?action= on the URL):
 *   GET  ?action=ballot           -> list of elections available to this voter + positions + candidates
 *   POST ?action=cast             -> submit votes for one election
 *                                    JSON: { election_id, votes: [ {position_id, candidate_id}, ... ] }
 *
 * Rules enforced:
 *   - Only logged-in voters with status = 'approved' may vote.
 *   - Election must be 'open' and within start/end times.
 *   - One voter may vote for each position only once (DB unique key).
 *   - The candidate must belong to the stated position.
 *   - Per-election has_voted flags prevent double submission.
 */

session_start();
require __DIR__ . '/db.php';

function require_voter(): array
{
    if (empty($_SESSION['voter_id'])) {
        json_response(['error' => 'You must log in as a voter.'], 401);
    }
    $stmt = db()->prepare(
        'SELECT id, student_id, full_name, status, has_voted_src, has_voted_dept, has_voted_hall, has_voted_class,
                campus_id, department_id, hall_id
         FROM voters WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['voter_id']]);
    $voter = $stmt->fetch();
    if (!$voter) {
        json_response(['error' => 'Voter not found.'], 404);
    }
    if ($voter['status'] !== 'approved') {
        json_response(['error' => 'Your account is not approved for voting.'], 403);
    }
    return $voter;
}

function election_is_open(array $election): bool
{
    return $election['status'] === 'open';
}

function has_voted_flag(array $voter, string $type): bool
{
    $map = [
        'src'          => 'has_voted_src',
        'departmental' => 'has_voted_dept',
        'hall'         => 'has_voted_hall',
        'class'        => 'has_voted_class',
    ];
    $col = $map[$type] ?? null;
    if (!$col) return false;
    return (int)$voter[$col] === 1;
}

function set_voted_flag(PDO $pdo, int $voter_id, string $type): void
{
    $map = [
        'src'          => 'has_voted_src',
        'departmental' => 'has_voted_dept',
        'hall'         => 'has_voted_hall',
        'class'        => 'has_voted_class',
    ];
    $col = $map[$type] ?? null;
    if (!$col) return;
    $pdo->prepare("UPDATE voters SET $col = 1 WHERE id = ?")->execute([$voter_id]);
}

/**
 * Return all elections this voter is eligible for, with open/closed status.
 */
function voter_elections(array $voter): array
{
    $pdo = db();
    $out = [];

    // SRC elections for the voter's campus
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE type = "src" AND campus_id = ? ORDER BY id');
    $stmt->execute([$voter['campus_id']]);
    foreach ($stmt->fetchAll() as $e) {
        $e['is_open']  = election_is_open($e);
        $e['voted']    = has_voted_flag($voter, $e['type']);
        $out[] = $e;
    }

    // Departmental election for the voter's department
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE type = "departmental" AND department_id = ? ORDER BY id');
    $stmt->execute([$voter['department_id']]);
    foreach ($stmt->fetchAll() as $e) {
        $e['is_open']  = election_is_open($e);
        $e['voted']    = has_voted_flag($voter, $e['type']);
        $out[] = $e;
    }

    // Hall election for the voter's hall
    if (!empty($voter['hall_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM elections WHERE type = "hall" AND hall_id = ? ORDER BY id');
        $stmt->execute([$voter['hall_id']]);
        foreach ($stmt->fetchAll() as $e) {
            $e['is_open']  = election_is_open($e);
            $e['voted']    = has_voted_flag($voter, $e['type']);
            $out[] = $e;
        }
    }

    // Class elections for the voter's department (same dept, level-based)
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE type = "class" AND department_id = ? ORDER BY id');
    $stmt->execute([$voter['department_id']]);
    foreach ($stmt->fetchAll() as $e) {
        $e['is_open']  = election_is_open($e);
        $e['voted']    = has_voted_flag($voter, $e['type']);
        $out[] = $e;
    }

    return $out;
}

/**
 * Load positions + candidates for a single election.
 */
function ballot_for_election(int $election_id): array
{
    $stmt = db()->prepare(
        'SELECT id, title, description, max_votes, display_order
         FROM positions WHERE election_id = ? ORDER BY display_order, id'
    );
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll();

    $out = [];
    foreach ($positions as $pos) {
        $stmt = db()->prepare(
            'SELECT c.id, c.full_name, c.student_id, c.manifesto, c.photo_url,
                    d.name AS department
             FROM candidates c
             JOIN departments d ON d.id = c.department_id
             WHERE c.position_id = ? ORDER BY c.id'
        );
        $stmt->execute([$pos['id']]);
        $pos['candidates'] = $stmt->fetchAll();
        $out[] = $pos;
    }
    return $out;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ----------------------------------------------------
        //  Get ballot — all elections available to this voter
        // ----------------------------------------------------
        case 'ballot':
            $voter = require_voter();
            $elections = voter_elections($voter);

            $out = [];
            foreach ($elections as $e) {
                $out[] = [
                    'id'         => (int)$e['id'],
                    'title'      => $e['title'],
                    'type'       => $e['type'],
                    'status'     => $e['status'],
                    'is_open'    => $e['is_open'],
                    'voted'      => $e['voted'],
                    'start_time' => $e['start_time'],
                    'end_time'   => $e['end_time'],
                    'positions'  => ballot_for_election((int)$e['id']),
                ];
            }

            json_response([
                'voter' => [
                    'id'        => (int)$voter['id'],
                    'full_name' => $voter['full_name'],
                    'student_id'=> $voter['student_id'],
                ],
                'elections' => $out,
            ]);
            break;

        // ----------------------------------------------------
        //  Cast vote(s) for a specific election
        // ----------------------------------------------------
        case 'cast':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $voter = require_voter();

            $b = read_json_body();
            $election_id = (int)($b['election_id'] ?? 0);
            $votes = $b['votes'] ?? [];

            if ($election_id <= 0) {
                json_response(['error' => 'No election specified.'], 422);
            }
            if (!is_array($votes) || count($votes) === 0) {
                json_response(['error' => 'No votes submitted.'], 422);
            }

            // fetch the election
            $stmt = db()->prepare('SELECT * FROM elections WHERE id = ? LIMIT 1');
            $stmt->execute([$election_id]);
            $election = $stmt->fetch();
            if (!$election) {
                json_response(['error' => 'Election not found.'], 404);
            }

            // check it's open
            if (!election_is_open($election)) {
                json_response(['error' => 'Voting is not open for this election right now.'], 403);
            }

            // check the voter hasn't already voted in this type of election
            if (has_voted_flag($voter, $election['type'])) {
                json_response(['error' => 'You have already voted in this election.'], 403);
            }

            // verify eligibility: voter must be in the right scope
            if ($election['type'] === 'src' && (int)$election['campus_id'] !== (int)$voter['campus_id']) {
                json_response(['error' => 'You are not eligible for this election.'], 403);
            }
            if ($election['type'] === 'departmental' && (int)$election['department_id'] !== (int)$voter['department_id']) {
                json_response(['error' => 'You are not eligible for this departmental election.'], 403);
            }
            if ($election['type'] === 'hall' && (int)$election['hall_id'] !== (int)$voter['hall_id']) {
                json_response(['error' => 'You are not eligible for this hall election.'], 403);
            }
            if ($election['type'] === 'class' && (int)$election['department_id'] !== (int)$voter['department_id']) {
                json_response(['error' => 'You are not eligible for this class election.'], 403);
            }

            $pdo = db();
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                'INSERT INTO votes (position_id, candidate_id, voter_id)
                 VALUES (?,?,?)'
            );
            $checkCand = $pdo->prepare(
                'SELECT id FROM candidates WHERE id = ? AND position_id = ? LIMIT 1'
            );
            $checkPos = $pdo->prepare(
                'SELECT id FROM positions WHERE id = ? AND election_id = ? LIMIT 1'
            );
            $seenPos = [];

            foreach ($votes as $v) {
                $position_id  = (int)($v['position_id']  ?? 0);
                $candidate_id = (int)($v['candidate_id'] ?? 0);
                if ($position_id <= 0 || $candidate_id <= 0) {
                    $pdo->rollBack();
                    json_response(['error' => 'Invalid vote entry.'], 422);
                }
                // position belongs to this election
                $checkPos->execute([$position_id, $election_id]);
                if (!$checkPos->fetch()) {
                    $pdo->rollBack();
                    json_response(['error' => 'Position does not belong to this election.'], 422);
                }
                if (isset($seenPos[$position_id])) {
                    $pdo->rollBack();
                    json_response(['error' => "You can vote only once for position {$position_id}."], 422);
                }
                $seenPos[$position_id] = true;

                $checkCand->execute([$candidate_id, $position_id]);
                if (!$checkCand->fetch()) {
                    $pdo->rollBack();
                    json_response(['error' => 'Candidate does not belong to that position.'], 422);
                }

                $insert->execute([$position_id, $candidate_id, $voter['id']]);
            }

            // mark voter as having voted for this election type
            set_voted_flag($pdo, (int)$voter['id'], $election['type']);

            $pdo->commit();
            json_response(['message' => 'Your vote has been recorded. Thank you!']);
            break;

        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
}
