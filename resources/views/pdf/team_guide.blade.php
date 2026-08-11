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
  <tr><td>Frontend Dev</td><td>feature/ui-auth</td><td>Login and register pages</td></tr>
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
<p style="font-size:11px; color:#718096;">Re-create them at any time with <code>php artisan db:seed --class=UserSeeder</code>. The last two deliberately have no name, matching what a real registration now produces — use them to catch anything that still assumes a name exists. Anywhere a name would be shown (including the PDF watermark) falls back to the ID number.</p>

<!-- ============ 6 — NEW ============ -->
<div class="page-break"></div>
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
  <span class="badge post">POST</span> /auth/register &nbsp;&nbsp;
  <span class="badge post">POST</span> /auth/login
</div>

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

<h2 class="sub">8.3 Staff and Super Admin only</h2>
<div class="badge-row">
  <span class="badge post">POST</span> /theses &nbsp;&nbsp;
  <span class="badge put">PUT</span> /theses/{id} &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /theses/{id}/archive &nbsp;&nbsp;
  <span class="badge patch">PATCH</span> /theses/{id}/status<br>
  <span class="badge get">GET</span> /theses/{id}/download &nbsp;&nbsp;
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
<p style="font-size:11px; color:#718096;">Note: staff can also reach <code>/users/{id}/reset-password</code> and <code>DELETE /theses/{id}</code> / <code>DELETE /users/{id}</code> if individually granted the matching permission by a Super Admin (reset_passwords, delete_documents, delete_accounts).</p>

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
