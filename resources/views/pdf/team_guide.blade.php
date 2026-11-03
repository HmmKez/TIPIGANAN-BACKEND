<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>TIPIGANAN Team Guide</title>
<style>
  @page { size: A4; margin: 22mm 18mm 20mm; }
  body {
    font-family: 'Helvetica', Arial, sans-serif;
    color: #232a3b;
    font-size: 12.5px;
    line-height: 1.55;
    margin: 0;
  }
  .cover {
    text-align: center;
    padding-top: 140px;
    page-break-after: always;
  }
  .cover .brand {
    font-size: 46px;
    font-weight: bold;
    color: #1e3a6e;
    letter-spacing: 1px;
  }
  .cover .sub {
    font-size: 15px;
    color: #4a5568;
    margin-top: 6px;
  }
  .cover .rule {
    width: 90px; height: 3px; background: #345fcf;
    margin: 28px auto;
  }
  .cover .doctitle {
    font-size: 13px; font-weight: bold; letter-spacing: 1px;
    color: #345fcf; text-transform: uppercase;
  }
  .cover .meta {
    margin-top: 220px; font-size: 12px; color: #718096;
  }
  .cover .meta b { color: #4a5568; }

  h1.section {
    font-size: 18px;
    font-weight: bold;
    color: #ffffff;
    background: #1e3a6e;
    padding: 8px 14px;
    margin: 0 0 14px;
    page-break-after: avoid;
  }
  h2.sub {
    font-size: 14px;
    font-weight: bold;
    color: #1e3a6e;
    margin: 20px 0 8px;
    page-break-after: avoid;
  }
  h3.subsub {
    font-size: 12.5px;
    font-weight: bold;
    color: #345fcf;
    margin: 14px 0 6px;
  }
  p { margin: 0 0 10px; }
  ul, ol { margin: 6px 0 12px 20px; padding: 0; }
  li { margin-bottom: 4px; }

  table.doc-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0 16px;
    font-size: 11.5px;
  }
  table.doc-table th {
    background: #1e3a6e;
    color: #fff;
    text-align: left;
    padding: 7px 10px;
    font-weight: bold;
  }
  table.doc-table td {
    padding: 6px 10px;
    border-bottom: 1px solid #dbe2ee;
  }
  table.doc-table tr:nth-child(even) td { background: #f4f7fc; }
  code {
    font-family: 'Courier New', monospace;
    background: #eaf0ff;
    color: #1e3a6e;
    padding: 1px 5px;
    font-size: 11px;
  }
  pre {
    background: #16213a;
    color: #d8e2f7;
    padding: 12px 14px;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    line-height: 1.6;
    margin: 8px 0 14px;
  }
  pre .cm { color: #7b8bab; }
  .callout {
    padding: 10px 14px;
    margin: 10px 0 16px;
    font-size: 11.5px;
  }
  .callout .ct { font-weight: bold; display: block; margin-bottom: 5px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
  .callout.green { background: #e3f6ee; border-left: 4px solid #1e8a5f; }
  .callout.green .ct { color: #1e8a5f; }
  .callout.blue { background: #eaf0ff; border-left: 4px solid #345fcf; }
  .callout.blue .ct { color: #1e3a6e; }
  .callout.purple { background: #f1eaff; border-left: 4px solid #7c4fd1; }
  .callout.purple .ct { color: #5b32a8; }
  .callout.amber { background: #fdf1e2; border-left: 4px solid #c9781a; }
  .callout.amber .ct { color: #a35f0e; }
  .callout ul { margin: 4px 0 0 18px; }

  .badge-row { display: block; margin: 8px 0 16px; }
  .badge {
    display: inline-block;
    background: #1e3a6e; color: #fff;
    font-size: 10px; font-weight: bold;
    padding: 2px 8px;
    margin-right: 6px;
  }
  .badge.get { background: #1e8a5f; }
  .badge.post { background: #345fcf; }
  .badge.put { background: #a35f0e; }
  .badge.patch { background: #7c4fd1; }
  .badge.delete { background: #c0392b; }

  .toc { page-break-after: always; }
  .toc table { width: 100%; border-collapse: collapse; }
  .toc td {
    padding: 8px 0;
    border-bottom: 1px dotted #cbd5e1;
    font-size: 13px;
  }
  .toc td.num { text-align: right; color: #1e3a6e; font-weight: bold; width: 30px; }
  .toc .new-tag {
    background: #1e8a5f; color: #fff; font-size: 9px; font-weight: bold;
    padding: 1px 6px; margin-left: 8px;
  }

  .footer-note {
    text-align: center; color: #a0aec0; font-size: 10px; margin-top: 30px;
  }
  .page-break { page-break-before: always; }
</style>
</head>
<body>

<div class="cover">
  <div class="doctitle">Capstone Project Documentation &middot; Group 7</div>
  <div class="brand">TIPIGANAN</div>
  <div class="sub">MDC Online Repository of Special and Rare Collections</div>
  <div class="rule"></div>
  <div class="doctitle">Team Onboarding &amp; Collaboration Guide</div>
  <div class="meta">
    Concha &middot; Esto &middot; Mendez &middot; Miano<br><br>
    <b>Updated:</b> {{ $generatedAt }} — adds Meilisearch setup (Section 6)
  </div>
</div>

<div class="toc">
  <h1 class="section">Contents</h1>
  <table>
    <tr><td>1. How the Team Works Together</td><td class="num">1</td></tr>
    <tr><td>2. How the Frontend Developer Tests Their Work</td><td class="num">2</td></tr>
    <tr><td>3. GitHub Setup</td><td class="num">3</td></tr>
    <tr><td>4. Daily Git Workflow</td><td class="num">4</td></tr>
    <tr><td>5. Local Environment Setup</td><td class="num">5</td></tr>
    <tr><td>6. Meilisearch Setup <span class="new-tag">NEW</span></td><td class="num">6</td></tr>
    <tr><td>7. Frontend: Connecting to the Backend</td><td class="num">7</td></tr>
    <tr><td>8. API Endpoint Reference</td><td class="num">8</td></tr>
    <tr><td>9. Team Rules</td><td class="num">9</td></tr>
  </table>
</div>

<!-- ============ 1 ============ -->
<h1 class="section">1. How the Team Works Together</h1>
<p>TIPIGANAN is split into two separate projects — a Laravel backend and a React frontend. They run as independent applications that communicate through an API. No team member needs to touch the other side's codebase to do their job.</p>

<table class="doc-table">
  <tr><th>Role</th><th>Repository</th><th>Responsibilities</th></tr>
  <tr><td>Backend Dev 1</td><td>tipiganan-backend</td><td>API controllers, models, migrations, auth, search</td></tr>
  <tr><td>Backend Dev 2</td><td>tipiganan-backend</td><td>OCR pipeline, watermarking, reports, export features</td></tr>
  <tr><td>Frontend Dev</td><td>tipiganan-frontend</td><td>React pages, Axios API calls, PDF viewer, UI/UX</td></tr>
</table>

<h3 class="subsub">How the frontend and backend connect:</h3>
<div class="callout blue">
  <span class="ct">Connection explained</span>
  <ul>
    <li>Laravel runs at http://127.0.0.1:8000 (backend API)</li>
    <li>React runs at http://localhost:5173 (frontend UI)</li>
    <li>Axios sends HTTP requests from React to the Laravel API URL</li>
    <li>Laravel processes the request, queries MySQL, and sends back JSON data</li>
    <li>React receives the JSON and displays it on screen</li>
    <li>Both apps must run at the same time during development</li>
  </ul>
</div>

<!-- ============ 2 ============ -->
<h1 class="section">2. How the Frontend Developer Tests Their Work</h1>
<p>Since the team works from different households, every developer must run both projects on their own machine. The frontend developer does NOT edit any Laravel files — they only need the backend running locally so their Axios requests have somewhere to go.</p>

<h2 class="sub">2.1 Frontend developer — one-time backend setup</h2>
<div class="callout green">
  <span class="ct">Do this once — then never touch these files again</span>
  <ul>
    <li>Clone the backend repo (you will not edit it, just run it)</li>
    <li>Install dependencies with composer install</li>
    <li>Copy the environment file: cp .env.example .env</li>
    <li>Generate the app key: php artisan key:generate</li>
    <li>Update .env with your local MySQL credentials</li>
    <li>Run migrations and seeders to populate test data</li>
    <li>Start the server: php artisan serve</li>
    <li>The backend now runs at http://127.0.0.1:8000 — leave this terminal open</li>
  </ul>
</div>

<p><b>Full setup commands for the frontend developer:</b></p>
<pre><span class="cm"># -- BACKEND (run once, then leave it running) --</span>
git clone https://github.com/YOUR_USERNAME/tipiganan-backend.git
cd tipiganan-backend
composer install
cp .env.example .env
php artisan key:generate

<span class="cm"># Open .env and set:</span>
<span class="cm"># DB_DATABASE=tipiganan</span>
<span class="cm"># DB_USERNAME=root</span>
<span class="cm"># DB_PASSWORD=</span>

php artisan migrate
php artisan db:seed
php artisan serve  <span class="cm">&lt;- keep this terminal open</span>

<span class="cm"># -- FRONTEND (open a second terminal) --</span>
cd ../tipiganan-frontend
npm install
npm run dev  <span class="cm">&lt;- your actual work happens here</span></pre>

<div class="callout amber">
  <span class="ct">Important</span>
  <ul>
    <li>You need TWO terminals open at the same time — one for Laravel, one for React</li>
    <li>Never edit files inside tipiganan-backend — only clone and run it</li>
    <li>If the backend server stops, restart it with php artisan serve</li>
    <li>All your coding work stays inside the tipiganan-frontend folder only</li>
  </ul>
</div>

<!-- ============ 3 ============ -->
<div class="page-break"></div>
<h1 class="section">3. GitHub Setup</h1>

<h2 class="sub">3.1 Accepting the GitHub invite</h2>
<div class="callout green">
  <span class="ct">Steps</span>
  <ul>
    <li>Check your email for an invite from GitHub</li>
    <li>Click 'Accept invitation'</li>
    <li>You now have access to both private repositories</li>
  </ul>
</div>

<h2 class="sub">3.2 Cloning your repository</h2>
<p><b>Backend developers:</b></p>
<pre>git clone https://github.com/YOUR_USERNAME/tipiganan-backend.git</pre>
<p><b>Frontend developer — clone BOTH but only edit the frontend:</b></p>
<pre>git clone https://github.com/YOUR_USERNAME/tipiganan-backend.git   <span class="cm"># run only</span>
git clone https://github.com/YOUR_USERNAME/tipiganan-frontend.git  <span class="cm"># work here</span></pre>

<h2 class="sub">3.3 Branch structure</h2>
<p>We use a protected branching strategy. Nobody pushes directly to main or dev. All work happens on feature branches that get reviewed before merging.</p>
<pre>main       -&gt; stable finished code only — never push here directly
dev        -&gt; active development — always branch off this
feature/xxx -&gt; your individual work — branch off dev, merge back to dev</pre>

<h2 class="sub">3.4 Suggested feature branches per developer</h2>
<table class="doc-table">
  <tr><th>Who</th><th>Branch name</th><th>Purpose</th></tr>
  <tr><td>Backend Dev 1</td><td>feature/ocr-pipeline</td><td>Tesseract.js OCR for scanned PDFs</td></tr>
  <tr><td>Backend Dev 1</td><td>feature/pdf-watermark</td><td>Server-side watermark stamping</td></tr>
  <tr><td>Backend Dev 2</td><td>feature/export-reports</td><td>Export audit logs and reports to PDF</td></tr>
  <tr><td>Backend Dev 2</td><td>feature/meilisearch-setup</td><td>Switch Scout driver to Meilisearch</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-auth</td><td>Login page (the register page was removed on 2026-10-29)</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-browse</td><td>Homepage and thesis list</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-viewer</td><td>PDF viewer with watermark overlay</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-search</td><td>Search results and filters</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-dashboard</td><td>Admin dashboard</td></tr>
  <tr><td>Frontend Dev</td><td>feature/ui-citations</td><td>APA/MLA citation copy panel</td></tr>
</table>

<!-- ============ 4 ============ -->
<div class="page-break"></div>
<h1 class="section">4. Daily Git Workflow</h1>
<p>Follow this exact workflow every single day. Skipping the pull at the start causes merge conflicts that waste everyone's time.</p>

<h2 class="sub">4.1 Starting a new feature</h2>
<pre><span class="cm"># Step 1 — Always get the latest code first</span>
git checkout dev
git pull origin dev

<span class="cm"># Step 2 — Create your feature branch</span>
git checkout -b feature/your-feature-name

<span class="cm"># Step 3 — Do your work, then save progress</span>
git add .
git commit -m "Add login page with form validation"

<span class="cm"># Step 4 — Push your branch to GitHub</span>
git push origin feature/your-feature-name</pre>

<h2 class="sub">4.2 Merging your feature into dev (on GitHub website)</h2>
<div class="callout purple">
  <span class="ct">Pull request steps</span>
  <ul>
    <li>Go to the repository on github.com</li>
    <li>You will see a yellow banner: 'your-branch had recent pushes'</li>
    <li>Click 'Compare and pull request'</li>
    <li>Write a short description of what you built</li>
    <li>Assign a teammate to review it</li>
    <li>Once reviewed and approved, click 'Merge pull request'</li>
    <li>Delete the branch after merging to keep the repo clean</li>
  </ul>
</div>

<h2 class="sub">4.3 Getting your teammate's latest work</h2>
<pre>git checkout dev
git pull origin dev
git checkout feature/your-feature-name
git merge dev</pre>
<p style="font-size:11px; color:#718096;"><b>After any pull that touched the backend, run <code>php artisan migrate</code>.</b> If someone added a database column and you skip this, the app will fail with confusing "column not found" errors that look like a bug in their code rather than a missing step in yours. It is safe to run when there is nothing new — it simply reports "Nothing to migrate".</p>

<h2 class="sub">4.4 Quick command reference</h2>
<table class="doc-table">
  <tr><th>Command</th><th>What it does</th></tr>
  <tr><td><code>git status</code></td><td>See changed files and current branch</td></tr>
  <tr><td><code>git branch -a</code></td><td>See all branches</td></tr>
  <tr><td><code>git checkout branch-name</code></td><td>Switch to an existing branch</td></tr>
  <tr><td><code>git checkout -b feature/name</code></td><td>Create and switch to a new branch</td></tr>
  <tr><td><code>git pull origin dev</code></td><td>Get latest changes from dev</td></tr>
  <tr><td><code>git add .</code></td><td>Stage all changed files</td></tr>
  <tr><td><code>git commit -m 'message'</code></td><td>Save changes with a description</td></tr>
  <tr><td><code>git push origin feature/name</code></td><td>Push your branch to GitHub</td></tr>
  <tr><td><code>git merge dev</code></td><td>Merge dev changes into your branch</td></tr>
  <tr><td><code>git log --oneline</code></td><td>See recent commits</td></tr>
</table>

<!-- ============ 5 ============ -->
<div class="page-break"></div>
<h1 class="section">5. Local Environment Setup</h1>

<h2 class="sub">5.1 Backend developers</h2>
<div class="callout green">
  <span class="ct">Requirements</span>
  <ul>
    <li>PHP 8.3+ — comes bundled with Laragon</li>
    <li>Composer — https://getcomposer.org</li>
    <li>MySQL 8+ — comes bundled with Laragon</li>
    <li>Laragon — https://laragon.org/download (recommended for Windows)</li>
    <li>Git — https://git-scm.com</li>
    <li>VS Code — https://code.visualstudio.com</li>
  </ul>
</div>
<pre>git clone https://github.com/YOUR_USERNAME/tipiganan-backend.git
cd tipiganan-backend
composer install
cp .env.example .env
php artisan key:generate
<span class="cm"># Update .env: DB_DATABASE=tipiganan DB_USERNAME=root DB_PASSWORD=</span>
php artisan migrate
php artisan db:seed
php artisan serve  <span class="cm"># runs at http://127.0.0.1:8000</span></pre>

<h2 class="sub">5.2 Frontend developer</h2>
<div class="callout blue">
  <span class="ct">Requirements</span>
  <ul>
    <li>Node.js 18+ — https://nodejs.org (use the LTS version)</li>
    <li>npm — comes with Node.js</li>
    <li>Git — https://git-scm.com</li>
    <li>VS Code — https://code.visualstudio.com</li>
    <li>The backend must also be running locally — see Section 2</li>
  </ul>
</div>
<pre>git clone https://github.com/YOUR_USERNAME/tipiganan-frontend.git
cd tipiganan-frontend
npm install
npm run dev  <span class="cm"># runs at http://localhost:5173</span></pre>

<h2 class="sub">5.3 Default test accounts</h2>
<p><b>Sign in with the ID number, not the email.</b> Accounts are identified by the school's 5-digit student/teacher ID — that is the key the school's API uses to return a person's name and details, which is why registration no longer asks for a name. The email is kept only as a contact address.</p>
<table class="doc-table">
  <tr><th>ID Number</th><th>Role</th><th>Email (contact only)</th><th>Password</th></tr>
  <tr><td>90001</td><td>Super Admin</td><td>superadmin@tipiganan.com</td><td>password</td></tr>
  <tr><td>90002</td><td>Staff</td><td>staff@tipiganan.com</td><td>password</td></tr>
  <tr><td>90003</td><td>Student</td><td>student@tipiganan.com</td><td>password</td></tr>
  <tr><td>90004</td><td>Teacher</td><td>teacher@tipiganan.com</td><td>password</td></tr>
  <tr><td>90005</td><td>Student <i>(no name set)</i></td><td>student2@tipiganan.com</td><td>password</td></tr>
  <tr><td>90006</td><td>Teacher <i>(no name set)</i></td><td>teacher2@tipiganan.com</td><td>password</td></tr>
</table>
<p style="font-size:11px; color:#718096;">Re-create them at any time with <code>php artisan db:seed --class=UserSeeder</code>. The last two deliberately have no name — use them to catch anything that still assumes a name exists. Anywhere a name would be shown (including the PDF watermark) falls back to the ID number.</p>
<p style="font-size:11px; color:#718096;">These six sit in a reserved <b>9xxxx</b> block that the school's real ID numbers will never use, and they are flagged <code>directory_exempt</code> — so switching on ID verification (section 5.4) can never lock you out of your own demo accounts. <b>The one exception is the school list (CSV) in section 5.4: there nobody is exempt</b>, so the demo accounts must be on the imported list. The sample file includes all of them. <b>On Kent's machine only 90001–90004 can sign in</b> since 2026-10-29: every other account was set to need activation at the library (<code>php artisan accounts:require-activation --keep=90001 --keep=90002 --keep=90003 --keep=90004</code>). Your own database is not changed unless you run that command.</p>

<h2 class="sub">5.4 Accounts are activated at the library, from the school's list <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">CHANGED 2026-10-29</span></h2>
<p><b>There is no Register page.</b> Nobody can create an account online. A student or teacher goes to the library with their school ID card, and a staff member activates their account on the <b>School Records</b> page (Administration menu, every staff member has it by default):</p>
<table class="doc-table">
  <tr><th>Step</th><th>Who</th><th>What happens</th></tr>
  <tr><td>1. ID number</td><td>Staff</td><td>Types the person's 5-digit ID number and presses <b>Look up</b>. Refused if it is not on the school's list, if it already has an active account (reset the password in User Management instead), if staff deactivated that account by hand, or, unless you are a Super Admin, if it is an earlier staff or Super Admin account.</td></tr>
  <tr><td>2. Confirm the name</td><td>Staff</td><td>The system shows the name the list holds for that ID number, and the role the account will get. Staff compare it with the school ID card and tick <b>"I've checked their school ID card"</b>. Continue stays disabled until they do.</td></tr>
  <tr><td>3. Email and password</td><td>The person</td><td>Staff turn the screen around. The person types their own email and a password twice (8 to 72 characters with upper-case, lower-case and a number). Staff never need to know it.</td></tr>
  <tr><td>Done</td><td>—</td><td>The account is created; the audit trail records which staff member activated it. Everything typed is cleared for the next person. The person signs in with their ID number and that password.</td></tr>
</table>
<p><b>The school's list (CSV).</b> Activation only works for ID numbers on the list the Super Admin imported: a CSV exported from the school's database with a header row and the columns <code>id_number</code>, <code>first_name</code>, <code>last_name</code> (optional) and <code>role</code> (student, teacher, staff or super_admin). Only accounts on the list can sign in or keep using the system — <b>every role, staff and Super Admin included</b>. Nothing is enforced until a list is imported. To try it: <code>SCHOOL_DIRECTORY_DRIVER=csv</code> (the default in <code>.env.example</code> now), <code>php artisan config:clear</code>, then <code>php artisan school:import-roster database/fixtures/school-roster-sample.csv</code>. The sample holds all the demo accounts plus test people you can activate: 10003 Ma. Teresa, 10004 Niño, 00042 Rowena Mae, 20001 Angela (teacher), 20002 Josefina (staff row).</p>
<p><b>Earlier accounts.</b> Accounts made under the old self-registration can be set to need activation (<code>php artisan accounts:require-activation --keep=ID …</code>). They cannot sign in until activated; activating keeps the same account (bookmarks, history), gives it the new email and password, and gives a student or teacher account the role on the list. Staff can activate only student and teacher accounts; a waiting staff or Super Admin account, or one staff deactivated, needs a Super Admin. Until then they are not listed in User Management; School Records shows them as "Not activated yet".</p>
<p><b>Importing a new list</b> (each semester): accounts still on it carry on with their own passwords. Every account <b>not</b> on it is <b>switched off</b> — signed out at once and unable to sign in; its password is kept. If a later list has that ID again, the account <b>switches back on by itself</b>. An account staff deactivated by hand stays off, and an account off the list cannot be activated by hand. Check file on the School Records page shows every group before anything changes, with true totals. Sections over 10 names are folded, the screen lists the first 50, and <b>Download full lists</b> saves a separate CSV for each section. The list and account changes are saved as one step in groups of 500, so a failure changes nothing and 3,500 changed accounts took 4.5 seconds. The "not on the list" warning has a <b>Hide</b> link that lasts until the next import, in that browser.</p>
<p><b>When a new list differs from an account</b> <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">NEW 2026-11-04</span>. Student and teacher accounts follow the list's role; staff and Super Admin roles never change on import. If the name differs (capitals and spacing do not count), the account is signed out and must be activated again, because the ID number may now belong to someone else. At the desk, staff see both names and choose <b>Same person</b> (keeps bookmarks and history, takes the new name) or <b>Different person</b> (a new account; the old one loses its ID and stays closed, keeping its records). The person doing the import is the exception: their name just updates. Waiting staff or Super Admin accounts, and waiting accounts staff deactivated, can only be activated by a Super Admin.</p>
<p><b>Going live: the first Super Admin</b> <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">NEW 2026-11-04</span>. Follow <code>deploy/README.md</code>: <code>deploy/provision.sh</code> sets up a new Ubuntu 24.04 server, <code>deploy/update.sh</code> installs later versions, and <code>php artisan deploy:check</code> reports what is wrong at any time. These scripts have not yet been run on a real Linux server. With <code>APP_ENV=production</code> the seeders create only roles and permissions. Then run <code>php artisan accounts:create-super-admin</code> in a terminal on the server. Let the librarian type their own password. It works only while no active Super Admin exists. They then sign in and import the list on School Records; their own ID number must be on every list. No terminal is needed after that.</p>
<table class="doc-table">
  <tr><th>Setting in .env</th><th>What happens</th></tr>
  <tr><td><code>SCHOOL_DIRECTORY_DRIVER=csv</code></td><td><b>Default.</b> The school list is enforced at sign-in (once imported).</td></tr>
  <tr><td><code>SCHOOL_DIRECTORY_DRIVER=off</code></td><td>The list is not checked at sign-in. Activation still requires the list.</td></tr>
  <tr><td><code>fake</code> / <code>http</code></td><td>Left from the earlier plan of checking IDs against the school's own system through an API. No screen uses them since registration was removed; kept in case the school provides an API later.</td></tr>
</table>
<p style="font-size:11px; color:#718096;"><b>Three things not to undo.</b> (1) Activation never grants staff or Super Admin powers: those rows become teacher-level accounts, and the Super Admin gives the role in User Management. (2) The person types the password, not the staff member — the panel clears every field after each attempt, and the boxes use <code>autocomplete="new-password"</code> so the browser on the desk does not save them. (3) The import refuses a list that leaves out the Super Admin doing it, and asks for confirmation when a list is under 80% of the previous one — both to stop a bad file locking everyone out. The starting password (KEN_10001) and the public ID check from the week before are gone: anyone who knew someone's name and ID number could have registered as them.</p>
<p style="font-size:11px; color:#718096;">The test suite pins the driver to <code>off</code> in <code>phpunit.xml</code> regardless of your <code>.env</code>, so switching your own machine to <code>fake</code> will not break anyone's tests. Tests that need verification switch it on themselves.</p>

<h2 class="sub">5.5 Scan inbox — filing a scanned thesis <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">NEW</span></h2>
<p>The Upload Item page lists PDFs the library scanner has already produced, so staff attach one with a click instead of hunting for it in a file browser. Choosing a scan replaces the usual "browse for a file" step; the two are alternatives and the backend requires exactly one.</p>
<p><b>The system never talks to the scanner.</b> The library's scanner is a <b>ScanSnap SV600</b> — USB-only, and it reports a vendor-specific USB class, meaning no standard scanning protocol can reach it. It is driven solely by Fujitsu's ScanSnap Home. So ScanSnap Home is configured to save PDFs into a folder, and TIPIGANAN watches that folder. This is also why it does not matter that a USB scanner has exactly one host PC: what is shared is the folder, not the device, so any number of staff machines can file the scans.</p>
<table class="doc-table">
  <tr><th>Setting in .env</th><th>What it does</th></tr>
  <tr><td><code>SCAN_INBOX_ENABLED</code></td><td>Turn the feature on or off. Default on.</td></tr>
  <tr><td><code>SCAN_INBOX_PATH</code></td><td>The folder to watch. Blank uses <code>storage/app/scan-inbox</code> for local development. In production this points at the NAS share ScanSnap Home writes to. <b>Use forward slashes on Windows</b> — a backslash is an escape character to dotenv and breaks the whole file.</td></tr>
  <tr><td><code>SCAN_INBOX_SETTLE_SECONDS</code></td><td>How long a file must sit untouched before it is offered. Default 5. A scanner writing a large PDF leaves a file that exists but is not finished, and attaching one mid-write gives a corrupt document.</td></tr>
</table>
<p><b>Each waiting scan has a View button</b> that opens the PDF in a new tab, because scanner file names say nothing about which thesis is inside. <b>Discard always asks first</b>, on the app's own confirmation screen: it shows the file's name, size, page count and scan time, says plainly that the file cannot be recovered, and offers "View it first" without closing. The list sits in a fixed-height box that scrolls, newest first, so a backlog can never push the rest of the page off screen. The heading counts everything waiting; at most 100 are listed (<code>SCAN_INBOX_MAX_LISTED</code>), and past that it says "(showing the newest 100)". File extensions are matched regardless of case, so <code>SCAN001.PDF</code> is picked up too.</p>
<p><b>To try it locally</b>, drop any PDF into <code>storage/app/scan-inbox</code> and open the Upload Item page — it appears in the list. Filing it moves the file into the repository and removes it from the folder, so the same scan cannot be filed twice by accident.</p>
<p style="font-size:11px; color:#718096;"><b>An empty list and an unreachable folder are shown differently, and that distinction matters.</b> "No scans waiting" means the folder is fine and empty; "the scan folder cannot be reached" almost always means the NAS share is not mounted. Reporting the second as the first would send staff to check the scanner when the problem is the storage.</p>
<p style="font-size:11px; color:#718096;">The filename in an import request comes from the browser, so <code>ScanInbox::resolve()</code> is the single place it is made safe — it strips any directory part and then checks the resolved path really is inside the inbox. <b>Any new method that accepts a filename must go through it.</b> There is a test that plants a real PDF outside the folder and proves it cannot be reached or deleted.</p>

<!-- ============ 6 — NEW ============ -->
<div class="page-break"></div>
<h2 class="sub">5.6 Reading uploaded documents (OCR) — in the request, or in the background <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">NEW</span></h2>
<p>When a thesis is uploaded, the system reads the PDF to fill in the Abstract and Keywords, using OCR when the document is a scan. This is slow: a real 36-page scanned thesis took <b>4 minutes 41 seconds</b>. Where that work happens is controlled by one setting.</p>
<table class="doc-table">
  <tr><th>Setting in .env</th><th>What happens</th></tr>
  <tr><td><code>QUEUE_CONNECTION=sync</code></td><td><b>Default.</b> The document is read during the upload request. Nothing extra to set up, but the staff member waits for it, and on <code>php artisan serve</code> that one request blocks the whole site until it finishes.</td></tr>
  <tr><td><code>QUEUE_CONNECTION=database</code></td><td>The upload returns in about a tenth of a second; the reading happens in a separate worker process. The edit screen shows "Reading the document…" and fills the Abstract and Keywords in by itself when the worker finishes.</td></tr>
</table>
<p><b>With <code>database</code> you must run a worker</b>, or uploaded documents are never read at all:</p>
<pre>php artisan queue:work --tries=1 --timeout=900</pre>
<p>A live server uses <code>database</code>, and <code>deploy/</code> keeps the worker running. <code>scripts\dev-services.ps1</code> starts it for you on a development machine and reports whether it is running. On a server it must be kept alive (Supervisor, or NSSM / Task Scheduler on Windows) and restarted after every deploy with <code>php artisan queue:restart</code> — a worker keeps the old code in memory until it is.</p>
<p style="font-size:11px; color:#718096;"><b>Why the default is still <code>sync</code>.</b> It works on any host with no extra moving parts. A missing worker is a silent failure — uploads succeed and abstracts simply never appear — so the edit screen shows a warning if a document has been waiting more than two minutes, and the services script reports the worker as DOWN. Use <code>sync</code> unless you have somewhere to keep a worker running.</p>
<p style="font-size:11px; color:#718096;"><b>If a reading is interrupted</b> — the request is cancelled, or a worker is restarted mid-job — the record can be left saying <code>processing</code> with nothing running to finish it. The edit screen notices after two minutes and tells staff to use <b>Re-extract from current file</b>, which starts the reading again. Nothing recovers it automatically.</p>
<p style="font-size:11px; color:#718096;">Two related details. A thesis carries its own <code>ocr_status</code> (<code>pending</code> → <code>processing</code> → <code>done</code> or <code>failed</code>); <b><code>done</code> means the reading finished, not that anything was found</b> — a scan too poor to read leaves the Abstract empty and is still done, because re-running it would produce the same nothing. And the upload's audit-trail entry is written <b>before</b> the reading starts, so a thesis can never exist in the repository without a record of who uploaded it, however the reading ends.</p>

<h1 class="section">6. Meilisearch Setup <span style="font-size:11px; background:#1e8a5f; padding:2px 8px;">NEW</span></h1>
<p>Meilisearch powers fast, typo-tolerant search across the repository. It runs as its own small local service alongside Laravel and React — one-time setup per developer machine, a few minutes total.</p>

<div class="callout green">
  <span class="ct">Nobody is blocked without this</span>
  <p style="margin:0;">If Meilisearch isn't running, search automatically falls back to a plain MySQL search, and uploading/editing/deleting collections works exactly the same either way. Do this whenever you get a chance — not before you can start other work.</p>
</div>

<h2 class="sub">6.1 One-time setup (every developer, own machine)</h2>
<ol>
  <li><b>Install Meilisearch.</b> Download the build for your OS from the <code>meilisearch/meilisearch</code> GitHub releases page, or use a package manager (see 6.2 below).</li>
  <li><b>Run it on port 7700.</b> No master key needed for local dev — matches the blank <code>MEILISEARCH_KEY</code> already in <code>.env</code>.
    <pre>meilisearch --http-addr 127.0.0.1:7700 --no-analytics</pre>
  </li>
  <li><b>Push the index settings</b> (one time per machine — tells Meilisearch which fields can be filtered on):
    <pre>php artisan scout:sync-index-settings</pre>
  </li>
  <li><b>Import existing theses</b> (requires your local MySQL to be running and seeded first):
    <pre>php artisan scout:import "App\Models\Thesis"</pre>
  </li>
  <li><b>Verify it's working:</b>
    <pre>curl http://127.0.0.1:7700/health
<span class="cm"># should return: {"status":"available"}</span></pre>
    Then try a search in the app — Browse or Search page, type a query.
  </li>
</ol>

<h2 class="sub">6.2 Auto-start (so you don't repeat this every session)</h2>
<table class="doc-table">
  <tr><th>OS</th><th>Method</th></tr>
  <tr><td>Windows</td><td>Drop a script in your Startup folder (<code>%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup</code>) that launches meilisearch.exe hidden via PowerShell (<code>-WindowStyle Hidden</code>). Check for an existing healthy instance first so it doesn't double-launch.</td></tr>
  <tr><td>macOS</td><td><code>brew install meilisearch</code> then <code>brew services start meilisearch</code> — Homebrew services auto-start on login.</td></tr>
  <tr><td>Linux</td><td><code>systemctl --user enable --now meilisearch</code> — runs as a user service, restarts on crash.</td></tr>
</table>

<h2 class="sub">6.3 Security note (dev vs. production)</h2>
<div class="callout amber">
  <span class="ct">Important</span>
  <p style="margin:0;">Running with no master key is fine for <b>local development only</b> — it's not reachable from outside your machine. In <b>production</b>, this changes: a master key is required (free, just a random string you generate — <code>openssl rand -base64 32</code>) and port 7700 must never be exposed to the public internet.</p>
</div>

<h2 class="sub">6.4 Quick reference</h2>
<table class="doc-table">
  <tr><th>Command</th><th>What it does</th></tr>
  <tr><td><code>curl http://127.0.0.1:7700/health</code></td><td>Confirms Meilisearch is running</td></tr>
  <tr><td><code>php artisan scout:sync-index-settings</code></td><td>Pushes filterable-attribute config (once per machine)</td></tr>
  <tr><td><code>php artisan scout:import "App\Models\Thesis"</code></td><td>Backfills all theses into the index</td></tr>
  <tr><td><code>php artisan scout:flush "App\Models\Thesis"</code></td><td>Clears the index (rarely needed)</td></tr>
</table>

<!-- ============ 7 (was 6) ============ -->
<div class="page-break"></div>
<h1 class="section">7. Frontend: Connecting to the Backend</h1>
<p>Create this file first in your frontend project. All API calls across the entire React app will import from it — you never hardcode URLs anywhere else.</p>

<h2 class="sub">7.1 Create src/api/axios.js</h2>
<pre>import axios from 'axios'

const api = axios.create({
  baseURL: 'http://127.0.0.1:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true,
})

<span class="cm">// Automatically attach token to every request</span>
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

<span class="cm">// Handle expired sessions — redirect to login</span>
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api</pre>

<h2 class="sub">7.2 Usage examples in React components</h2>
<pre>import api from '../api/axios'

<span class="cm">// Login — accounts sign in with their 5-digit school ID number, not an email</span>
const login = async (idNumber, password) => {
  const res = await api.post('/auth/login', { id_number: idNumber, password })
  localStorage.setItem('token', res.data.token)
}

<span class="cm">// Get list of theses (public)</span>
const getTheses = async () => {
  const res = await api.get('/theses')
  return res.data
}

<span class="cm">// Search with filters</span>
const search = async (query, categoryId) => {
  const res = await api.get(`/search?q=${query}&category_id=${categoryId}`)
  return res.data
}

<span class="cm">// Add to favorites (token attached automatically)</span>
const addFavorite = async (thesisId) => {
  await api.post(`/favorites/${thesisId}`)
}

<span class="cm">// Generate view token for PDF access</span>
const openThesis = async (thesisId) => {
  const res = await api.post(`/theses/${thesisId}/view-token`)
  return res.data.token  <span class="cm">// use this token to load the PDF</span>
}</pre>

<h2 class="sub">7.3 Phones and tablets — two conventions for tables <span style="font-size:11px; background:#1e8a5f; color:#fff; padding:2px 8px;">NEW</span></h2>
<p>Every screen is checked at 320, 360 and 375px wide. Tables need a little help, because a table that merely scrolls sideways inside its box never makes the <i>page</i> too wide — it just quietly hides its right-hand columns, including the Actions menu.</p>
<p><b>On phones (below 640px), a table can turn each row into a card.</b> Add <code>stack-on-phone</code> to the table, then mark its cells:</p>
<pre>&lt;table className="data-table stack-on-phone"&gt;
  ...
  &lt;td className="cell-primary"&gt;{title}&lt;/td&gt;          <span class="cm">// what the row IS — goes to the top, no label</span>
  &lt;td data-label="Status"&gt;{status}&lt;/td&gt;             <span class="cm">// shown as "STATUS ........ value"</span>
  &lt;td className="cell-block" data-label="Reason"&gt;   <span class="cm">// long text: label above, paragraph below</span>
  &lt;td className="cell-select"&gt;&lt;input type="checkbox"/&gt;&lt;/td&gt;  <span class="cm">// corner checkbox</span>
  &lt;td className="cell-actions"&gt;&lt;RowActions .../&gt;&lt;/td&gt;      <span class="cm">// goes to the bottom</span></pre>
<p><b>On tablets and laptops, the <code>cell-actions</code> column is pinned</b> to the right edge, so it stays reachable while the rest of the row scrolls under it. Mark the Actions cell <code>cell-actions</code> and this happens on its own.</p>
<p style="font-size:11px; color:#718096;">Two traps worth knowing. A CSS grid column written as <code>1fr</code> cannot shrink below its widest unbreakable text, so one long file name can widen a whole page on a phone — use <code>minmax(0, 1fr)</code>. And keep colour values as theme tokens (<code>var(--bg-card)</code>), never literals, or the element stops following dark mode.</p>

<!-- ============ 8 (was 7) ============ -->
<div class="page-break"></div>
<h1 class="section">8. API Endpoint Reference</h1>
<p>Base URL: <code>http://127.0.0.1:8000/api</code> | Protected routes require header: <code>Authorization: Bearer {token}</code></p>

<h2 class="sub">8.1 Public endpoints — no token needed</h2>
<div class="badge-row">
  <span class="badge get">GET</span> /theses &nbsp;&nbsp;
  <span class="badge get">GET</span> /theses/{id} &nbsp;&nbsp;
  <span class="badge get">GET</span> /categories &nbsp;&nbsp;
  <span class="badge get">GET</span> /categories/{id}<br>
  <span class="badge get">GET</span> /search?q=keyword &nbsp;&nbsp;
  <span class="badge get">GET</span> /theses/{id}/citations &nbsp;&nbsp;
  <span class="badge get">GET</span> /theses/{id}/citations/generate<br>
  <span class="badge post">POST</span> /auth/login
</div>
<h3 class="subsub">Who can see which items — one rule, applied everywhere</h3>
<p>Every thesis has a status, and the API decides what to return from the caller alone. The frontend does not need to filter anything itself, and should not try to.</p>
<table class="doc-table">
  <tr><th>Caller</th><th>Can see</th></tr>
  <tr><td>Guest (not signed in)</td><td><code>active</code> only</td></tr>
  <tr><td>Student / Teacher</td><td><code>active</code> + <code>restricted</code></td></tr>
  <tr><td>Staff / Super Admin</td><td><code>active</code> + <code>restricted</code> + <code>archived</code></td></tr>
</table>
<p style="font-size:11px; color:#718096;">The rule lives in one place, <code>Thesis::visibleStatusesFor()</code>, and applies to <code>GET /theses</code>, <code>GET /theses/{id}</code>, <code>GET /search</code> and <b>reading the PDF</b> (<code>POST /theses/{id}/view-token</code>) — so staff can read an archived thesis, and nobody else can. It also governs <code>?status=</code> on both the list and search: asking for a status you are not allowed to see returns <b>nothing</b>, rather than the rule being skipped. Browse's <b>Status</b> filter (staff and super admin only) is this parameter. Two bugs here were fixed on 2026-09-22 — <code>?status=archived</code> used to be honoured for anyone including guests, and archived items were hidden from staff too, which made the Edit screen fail to open for anything archived. On 2026-10-07 search and reading were brought under the same rule, and a leak was closed: the Archive button did not update the search index, so <b>guests could still find archived work by searching</b>. Search now also re-checks each result's status against the database, so a stale index can no longer show anything it shouldn't. If Meilisearch has been reset or was down while items changed, rebuild it: <code>php artisan scout:sync-index-settings</code> then <code>php artisan scout:import "App\Models\Thesis"</code>.</p>

<p style="font-size:11px; color:#718096;">There is <b>no registration endpoint</b>. Accounts are activated by staff at the library: <code>POST /accounts/activation/lookup</code> and <code>POST /accounts/activation</code> (staff and Super Admin, permission <code>activate_accounts</code>). See section 5.4.</p>

<h2 class="sub">8.2 Protected endpoints — all logged-in users</h2>
<div class="badge-row">
  <span class="badge post">POST</span> /auth/logout &nbsp;&nbsp;
  <span class="badge get">GET</span> /auth/me &nbsp;&nbsp;
  <span class="badge post">POST</span> /auth/change-password<br>
  <span class="badge get">GET</span> /profile &nbsp;&nbsp;
  <span class="badge put">PUT</span> /profile &nbsp;&nbsp;
  <span class="badge post">POST</span> /theses/{id}/view-token &nbsp;&nbsp;
  <span class="badge get">GET</span> /theses/serve/{token}<br>
  <span class="badge post">POST</span> /theses/{id}/citations/log &nbsp;&nbsp;
  <span class="badge get">GET</span> /favorites &nbsp;&nbsp;
  <span class="badge post">POST</span> /favorites/{thesisId} &nbsp;&nbsp;
  <span class="badge delete">DELETE</span> /favorites/{thesisId}<br>
  <span class="badge post">POST</span> /theses/{thesisId}/report
</div>
<p style="font-size:11px; color:#718096;"><b>Note on <code>/favorites</code>:</b> the feature is called <b>Bookmarks</b> everywhere a user sees it, and the database table is <code>bookmarks</code>. The API routes keep the older <code>/favorites</code> name deliberately — renaming them means coordinating a backend and a frontend deploy for something no user can see. Expect the mismatch; it is intentional.</p>

<h2 class="sub">8.3 Staff and Super Admin only</h2>
<div class="badge-row">
  <span class="badge post">POST</span> /theses &nbsp;&nbsp;
  <span class="badge put">PUT</span> /theses/{id} &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /theses/{id}/archive &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /theses/{id}/status<br>
  <span class="badge get">GET</span> /theses/{id}/download &nbsp;&nbsp;
  <span class="badge get">GET</span> /scan-inbox &nbsp;&nbsp;
  <span class="badge delete">DELETE</span> /scan-inbox/{name}<br>
  <span class="badge post">POST</span> /categories &nbsp;&nbsp;
  <span class="badge put">PUT</span> /categories/{id} &nbsp;&nbsp;
  <span class="badge delete">DELETE</span> /categories/{id}<br>
  <span class="badge get">GET</span> /users &nbsp;&nbsp;
  <span class="badge get">GET</span> /audit-logs &nbsp;&nbsp;
  <span class="badge get">GET</span> /reports/dashboard &nbsp;&nbsp;
  <span class="badge get">GET</span> /reports/most-cited<br>
  <span class="badge get">GET</span> /reports/by-department &nbsp;&nbsp;
  <span class="badge get">GET</span> /reports/by-year &nbsp;&nbsp;
  <span class="badge get">GET</span> /reports/most-searched &nbsp;&nbsp;
  <span class="badge get">GET</span> /reports/users-online<br>
  <span class="badge get">GET</span> /thesis-reports &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /thesis-reports/{id}/resolve
</div>

<h2 class="sub">8.4 Super Admin only</h2>
<div class="badge-row">
  <span class="badge patch">PATCH</span> /users/{id}/role &nbsp;&nbsp;
  <span class="badge put">PUT</span> /users/{id} &nbsp;&nbsp;
  <span class="badge delete">DELETE</span> /users/{id} &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /users/{id}/activate<br>
  <span class="badge patch">PATCH</span> /users/{id}/deactivate &nbsp;&nbsp;
  <span class="badge post">POST</span> /users/{id}/reset-password &nbsp;&nbsp;
  <span class="badge post">POST</span> /users/{id}/grant-permission<br>
  <span class="badge post">POST</span> /users/{id}/revoke-permission &nbsp;&nbsp;
  <span class="badge delete">DELETE</span> /theses/{id}
</div>
<p style="font-size:11px; color:#718096;">Note: staff can also reach <code>/users/{id}/reset-password</code> and <code>DELETE /theses/{id}</code> / <code>DELETE /users/{id}</code> if individually granted the matching permission by a Super Admin (reset_passwords, delete_documents, delete_accounts). They can reset passwords and delete accounts only for students and teachers.</p>

<!-- ============ 9 (was 8) ============ -->
<div class="page-break"></div>
<h1 class="section">9. Team Rules</h1>

<div class="callout blue">
  <span class="ct">Git rules — everyone must follow these</span>
  <ul>
    <li>Never push directly to main or dev</li>
    <li>Always pull from dev before starting any new work</li>
    <li>One feature per branch — do not mix multiple features in one branch</li>
    <li>Write clear commit messages: 'Add login form validation' not 'fix stuff'</li>
    <li>Commit often — small commits are easier to review and revert</li>
    <li>Delete your feature branch after it is merged into dev</li>
    <li>If you get a merge conflict, ask your team before force-pushing anything</li>
  </ul>
</div>

<div class="callout green">
  <span class="ct">Coordination rules</span>
  <ul>
    <li>The backend must be running (php artisan serve) for the frontend to work</li>
    <li>Both apps run simultaneously — coordinate with your team during testing</li>
    <li>If you change or add an API endpoint, notify the frontend dev immediately</li>
    <li>Test your endpoints in Postman before marking a feature as done</li>
    <li>Never commit your .env file — it contains sensitive database credentials</li>
    <li>Never commit the vendor/ or node_modules/ folders — they are in .gitignore</li>
  </ul>
</div>

<div class="callout purple">
  <span class="ct">Commit message format</span>
  <ul>
    <li>Add [feature name] &rarr; 'Add thesis upload endpoint'</li>
    <li>Fix [what was broken] &rarr; 'Fix signed URL expiry check'</li>
    <li>Update [what changed] &rarr; 'Update category validation rules'</li>
    <li>Remove [what was removed] &rarr; 'Remove unused test route'</li>
  </ul>
</div>

<p class="footer-note">TIPIGANAN — MDC Online Repository of Special and Rare Collections | Team Guide | Group 7 &middot; Generated {{ $generatedAt }}</p>

</body>
</html>
