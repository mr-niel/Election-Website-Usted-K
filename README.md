# AAMUSTED / USTED Student Election System

A complete school election system built with **pure HTML, CSS, JavaScript, and PHP**, using a **MySQL** database through **XAMPP**. Three PHP APIs power the whole app: authentication, voting, and results/admin.

This guide walks you through installing the database and connecting everything.

---

## 1. What's in this project

```
project/
├── index.html              Landing page
├── vote.html               Voter portal (register, login, cast ballot)
├── results.html            Public live results board (auto-refresh)
├── admin.html              Admin dashboard (approve voters, manage positions, open/close)
├── assets/
│   ├── css/styles.css      All styling (navy + gold university colors)
│   └── js/api.js           Shared JS that talks to the 3 PHP APIs
├── api/
│   ├── db.php              Database connection + shared helpers
│   ├── auth.php            API 1 — Authentication
│   ├── voting.php          API 2 — Voting
│   └── results.php         API 3 — Results & Admin management
└── database/
    └── election.sql        The full MySQL schema + seed data
```

---

## 2. Install XAMPP

1. Download XAMPP from https://www.apachefriends.org
2. Install it. Default location: `C:\xampp` (Windows) or `/Applications/XAMPP` (Mac).
3. Open the **XAMPP Control Panel**.
4. Click **Start** next to **Apache** and **MySQL**. Both should turn green.

---

## 3. Create the database (one step)

The `database/election.sql` file creates the database, all tables, and sample data in one go.

1. Open your browser and go to **http://localhost/phpmyadmin**
2. Click the **Import** tab at the top.
3. Click **Choose File** and select `database/election.sql` from this project.
4. Click **Go** at the bottom.

You should see a success message. A new database called **`aamusted_election`** appears in the left sidebar with these tables:

| Table         | Purpose                                                  |
|---------------|----------------------------------------------------------|
| `departments` | Faculties/schools in the university                      |
| `voters`      | Student accounts (must be approved before voting)        |
| `admins`      | Election officer accounts                                |
| `elections`   | One row per election cycle (year, type, start/end, status) |
| `positions`   | Roles being contested, linked to a specific election     |
| `candidates`  | People standing for a position, with their dept + student ID |
| `votes`       | One row per vote cast (enforces one-vote-per-position)   |

**Default admin login:** username `admin` · password `admin123`

> **IMPORTANT — fix the admin password.** The seed file contains a placeholder password hash that may not work on every MySQL version. After importing, run this in phpMyAdmin → SQL tab to set a known-good password:
>
> ```sql
> USE aamusted_election;
> UPDATE admins SET password_hash = '$2y$10$wH8cQ9oQ8oQ8oQ8oQ8oQ8uK0Q8oQ8oQ8oQ8oQ8oQ8oQ8oQ8oQ8' WHERE username = 'admin';
> ```
>
> **Easier method:** open `api/make_admin.php` in your browser once (see section 6 below) and it will create a correct password hash for you automatically. Then delete that file.

---

## 4. Put the project in XAMPP's web folder

XAMPP serves websites from the `htdocs` folder.

1. Copy the entire `project/` folder into:
   - **Windows:** `C:\xampp\htdocs\election\`
   - **Mac:** `/Applications/XAMPP/htdocs/election/`
2. Your folder structure should look like:
   ```
   C:\xampp\htdocs\election\
   ├── index.html
   ├── vote.html
   ├── results.html
   ├── admin.html
   ├── api\
   ├── assets\
   └── database\
   ```

---

## 5. Configure the database connection

Open `api/db.php` in a text editor. The top four lines are your database settings:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'aamusted_election');
define('DB_USER', 'root');
define('DB_PASS', '');
```

For a standard XAMPP install, **you do not need to change anything**. The defaults are:
- Host: `localhost`
- Database: `aamusted_election`
- User: `root`
- Password: *(empty)*

If you set a MySQL root password in XAMPP, put it in `DB_PASS`.

---

## 6. Create a working admin password (one-time)

Because PHP's `password_hash()` output can vary, run this helper once to generate a correct admin password:

1. Create a file `api/make_admin.php` in your project with this content:

```php
<?php
require __DIR__ . '/db.php';
$hash = password_hash('admin123', PASSWORD_DEFAULT);
db()->prepare("UPDATE admins SET password_hash = ? WHERE username = 'admin'")->execute([$hash]);
echo 'Admin password set to: admin123<br>Hash: ' . $hash . '<br><b>Delete this file now.</b>';
```

2. Open **http://localhost/election/api/make_admin.php** in your browser.
3. You'll see "Admin password set to: admin123".
4. **Delete `api/make_admin.php` immediately** so nobody can reset the password.

Now log in at **http://localhost/election/admin.html** with `admin` / `admin123`.

---

## 7. Open the website

Go to **http://localhost/election/** in your browser.

| Page                  | URL                                   | What it does                         |
|-----------------------|---------------------------------------|--------------------------------------|
| Home                  | `/election/index.html`                | Landing page                         |
| Voter portal          | `/election/vote.html`                 | Register, sign in, cast ballot       |
| Live results          | `/election/results.html`              | Public results (refreshes every 15s) |
| Admin dashboard       | `/election/admin.html`                | Manage everything                    |

---

## 8. The 3 APIs — reference

Every API returns JSON. All requests use `?action=NAME` on the URL to pick the endpoint.

### API 1 — `api/auth.php` (Authentication)

| Action         | Method | Body                                              | Result                              |
|----------------|--------|---------------------------------------------------|-------------------------------------|
| `register`     | POST   | `{student_id, full_name, email, password, department_id, level, gender}` | Creates a pending voter account |
| `login`        | POST   | `{student_id, password}`                          | Logs in a voter                     |
| `admin_login`  | POST   | `{username, password}`                            | Logs in an admin                    |
| `me`           | GET    | —                                                 | Returns current session info        |
| `logout`       | POST   | —                                                 | Destroys the session                |

**Example — voter login:**
```
POST http://localhost/election/api/auth.php?action=login
Content-Type: application/json

{ "student_id": "20210045", "password": "mypassword" }
```

### API 2 — `api/voting.php` (Voting)

| Action   | Method | Body                                  | Result                              |
|----------|--------|---------------------------------------|-------------------------------------|
| `ballot` | GET    | —                                     | Returns all positions + candidates + election status |
| `cast`   | POST   | `{ "votes": [ {position_id, candidate_id}, ... ] }` | Records the voter's ballot |

**Rules enforced by the API:**
- Voter must be logged in and approved.
- Election must be `open` and within the time window.
- One vote per position per voter (enforced by a unique database key).
- Candidate must belong to the stated position.
- `has_voted` flips to `1` after a successful cast.

**Example — cast vote:**
```
POST http://localhost/election/api/voting.php?action=cast
Content-Type: application/json

{
  "votes": [
    { "position_id": 1, "candidate_id": 2 },
    { "position_id": 2, "candidate_id": 3 }
  ]
}
```

### API 3 — `api/results.php` (Results & Admin)

**Public:**

| Action         | Method | Result                                  |
|----------------|--------|-----------------------------------------|
| `results`      | GET    | Live tally for every position (includes candidate department) |
| `turnout`      | GET    | Approved voters, votes cast, % turnout, male/female breakdown  |
| `departments`  | GET    | List of departments (for register form) |

**Admin (must be logged in as admin):**

| Action             | Method | Body                                          | Result                          |
|--------------------|--------|-----------------------------------------------|---------------------------------|
| `admin_positions`  | GET    | —                                             | Positions + candidates          |
| `add_position`     | POST   | `{title, description, max_votes, display_order}` | Adds a contested position    |
| `add_candidate`    | POST   | `{position_id, full_name, student_id, department_id, manifesto, photo_url}` | Adds a candidate |
| `pending_voters`   | GET    | —                                             | Voters awaiting approval        |
| `approve_voter`    | POST   | `{voter_id}`                                  | Approves a voter                |
| `reject_voter`     | POST   | `{voter_id}`                                  | Rejects a voter                 |
| `open_election`    | POST   | —                                             | Opens voting                    |
| `close_election`   | POST   | —                                             | Closes voting                   |
| `admin_stats`      | GET    | —                                             | Dashboard counts + election info|

**Example — get live results:**
```
GET http://localhost/election/api/results.php?action=results
```

---

## 9. How an election runs (step by step)

1. **Admin** logs into `/admin.html`.
2. **Admin** adds positions (e.g. SRC President, Vice President) and candidates.
3. **Students** register at `/vote.html` (accounts start as `pending`).
4. **Admin** reviews pending voters and clicks Approve.
5. **Admin** clicks **Open Voting** when ready.
6. **Approved students** sign in and cast their ballots (one per position).
7. **Everyone** can watch live results at `/results.html`.
8. **Admin** clicks **Close Voting** when the window ends.

---

## 10. Database schema details

If you want to inspect or edit the schema manually in phpMyAdmin:

- **`voters.has_voted`** — `0` = hasn't voted, `1` = has voted. Prevents double voting.
- **`votes` UNIQUE key `(voter_id, position_id)`** — the database itself blocks a voter from voting twice for the same position, even if the API is bypassed.
- **`voters.status`** — `pending` / `approved` / `rejected`. Only `approved` voters can cast votes.
- **`voters.gender`** — `male` / `female`. Used only for demographic turnout reporting.
- **`elections.status`** — `setup` / `open` / `closed`. Only `open` allows voting.
- **`positions.election_id`** — links each position to a specific election cycle, so multiple elections can coexist.
- **`candidates.department_id`** + **`candidates.student_id`** — identifies which faculty a candidate belongs to and their index number.
- All foreign keys use `ON DELETE CASCADE` where appropriate, so deleting a position removes its candidates and votes cleanly.

To add a new department in phpMyAdmin → SQL:
```sql
INSERT INTO departments (name, code) VALUES ('Faculty of Agriculture', 'FAG');
```

---

## 11. Troubleshooting

| Problem                              | Fix                                                            |
|--------------------------------------|----------------------------------------------------------------|
| Blank page / "This site can't be reached" | Start Apache in XAMPP Control Panel.                      |
| "No connection could be made" database error | Start MySQL in XAMPP Control Panel.                    |
| "Access denied for user 'root'"      | Set the correct password in `api/db.php` (`DB_PASS`).        |
| API returns `{"error":"Unknown action."}` | You forgot `?action=...` on the URL.                     |
| Voter login says "pending approval"  | Log in as admin and approve the voter first.                 |
| "Voting is not open right now"       | Admin must click **Open Voting** on the dashboard.           |
| Admin login fails                    | Run `make_admin.php` once (section 6) to reset the password. |
| Pages open as raw code in browser    | You opened the file directly. Use `http://localhost/election/...` instead. |

---

## 12. Security notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt) — never stored in plain text.
- Sessions use PHP's built-in `$_SESSION` (server-side, not visible in the browser).
- One-vote-per-position is enforced at the **database level** (unique key), not just in code.
- The ballot is secret: results only count candidate IDs, not who voted for whom. The `votes.voter_id` exists only to enforce "one voter, one vote" — it is never shown in results.
- **Before going live:** change the admin password from `admin123` to something strong, and delete `make_admin.php`.

---

Built for AAMUSTED / USTED. Good luck with your elections!
