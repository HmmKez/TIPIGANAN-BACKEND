<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>TIPIGANAN — System Functionalities & Technology Reference</title>
<style>
  @page { size: A4; margin: 20mm 16mm 18mm; }
  body {
    font-family: 'Helvetica', Arial, sans-serif;
    color: #232a3b;
    font-size: 11.5px;
    line-height: 1.55;
    margin: 0;
  }

  /* ---- Cover ---- */
  .cover { text-align: center; padding-top: 120px; page-break-after: always; }
  .cover .brand { font-size: 44px; font-weight: bold; color: #1e3a6e; letter-spacing: 1px; }
  .cover .sub { font-size: 14px; color: #4a5568; margin-top: 8px; }
  .cover .rule { width: 90px; height: 3px; background: #345fcf; margin: 26px auto; }
  .cover .doctitle {
    font-size: 13px; font-weight: bold; letter-spacing: 1.5px;
    color: #345fcf; text-transform: uppercase;
  }
  .cover .team { margin-top: 170px; font-size: 12px; color: #4a5568; }
  .cover .team b { color: #1e3a6e; }
  .cover .members { margin-top: 8px; font-size: 11.5px; color: #718096; line-height: 1.8; }
  .cover .meta { margin-top: 40px; font-size: 10.5px; color: #a0aec0; }

  /* ---- Headings ---- */
  h1.section {
    font-size: 16px; font-weight: bold; color: #ffffff;
    background: #1e3a6e; padding: 7px 12px; margin: 0 0 14px;
    page-break-after: avoid;
  }
  h2.sub {
    font-size: 13px; font-weight: bold; color: #1e3a6e;
    margin: 18px 0 6px; padding-bottom: 3px;
    border-bottom: 1px solid #dfe5ef;
    page-break-after: avoid;
  }
  p.lead { margin: 0 0 10px; }

  ul { margin: 4px 0 10px; padding-left: 16px; }
  li { margin-bottom: 4px; }

  /* ---- Tables ---- */
  table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
  th {
    background: #eef2f9; color: #1e3a6e; font-size: 10.5px;
    text-align: left; padding: 6px 8px; border: 1px solid #dfe5ef;
    text-transform: uppercase; letter-spacing: 0.4px;
  }
  td { padding: 6px 8px; border: 1px solid #dfe5ef; vertical-align: top; font-size: 11px; }
  td.k { font-weight: bold; color: #1e3a6e; width: 26%; }

  .req { color: #b3261e; font-weight: bold; font-size: 10px; }
  .opt { color: #1f7a45; font-weight: bold; font-size: 10px; }

  .note {
    background: #f6f8fc; border-left: 3px solid #345fcf;
    padding: 8px 11px; margin: 10px 0; font-size: 10.8px; color: #3d4658;
  }
  .pagebreak { page-break-before: always; }
  .footer-note { margin-top: 18px; font-size: 10px; color: #8794ad; font-style: italic; }
</style>
</head>
<body>

{{-- ============================= COVER ============================= --}}
<div class="cover">
  <div class="brand">TIPIGANAN</div>
  <div class="sub">MDC Online Repository of Special and Rare Collections</div>
  <div class="rule"></div>
  <div class="doctitle">System Functionalities &amp; Technology Reference</div>

  <div class="team">
    <b>GROUP 7</b>
    <div class="members">
      CONCHA, KENT AUGUSTINE G.<br>
      ESTO, KARL ADAM J.<br>
      MENDEZ, KYLE NANDZEL N.<br>
      MIANO, CHENA MAE M.
    </div>
  </div>

  <div class="meta">Mater Dei College — Cabulijan, Tubigon, Bohol<br>Generated {{ $generatedAt }}</div>
</div>

{{-- ========================= 1. OVERVIEW ========================= --}}
<h1 class="section">1. System Overview</h1>

<p class="lead">
  TIPIGANAN is a web-based academic repository that digitally stores, catalogues, searches, and
  provides controlled access to thesis manuscripts and special collections for Mater Dei College.
  It replaces the college's manual, print-only process — where documents were difficult to locate
  and vulnerable to loss or deterioration — with a centralized, searchable, access-controlled
  digital archive.
</p>

<h2 class="sub">1.1 Architecture</h2>
<p class="lead">The system uses a three-tier architecture:</p>
<table>
  <tr><td class="k">Presentation Layer</td><td>A React single-page application that runs in any modern browser on desktop, tablet, or phone. It handles all user interaction and renders documents in a secure in-browser viewer.</td></tr>
  <tr><td class="k">Application Layer</td><td>A Laravel REST API handling authentication, role-based access control, search, file validation, OCR, watermarking, citation generation, and audit logging.</td></tr>
  <tr><td class="k">Data Layer</td><td>A MySQL database for all records (thesis metadata, accounts, permissions, citations, activity logs) and a file store for the PDF documents themselves.</td></tr>
</table>

<h2 class="sub">1.2 Scope</h2>
<p class="lead">
  TIPIGANAN is deliberately scoped as a <b>single-institution, closed-access repository</b>. Every
  collection originates from Mater Dei College, and it is used only by the college's own students,
  teachers, and staff. There is no public or cross-institutional access. This scope directly
  shaped which capabilities were built and which were intentionally excluded (see Section 6).
</p>

{{-- ========================= 2. ROLES ========================= --}}
<h1 class="section">2. User Roles &amp; Access Control</h1>

<table>
  <tr>
    <th style="width:22%">Role</th>
    <th>Capabilities</th>
  </tr>
  <tr>
    <td class="k">Guest<br><span style="font-weight:normal;color:#718096">(not logged in)</span></td>
    <td>Browse and search Active collections and view their metadata (title, authors, abstract, keywords).
        <b>Cannot open any document</b> — opening a PDF requires an account.</td>
  </tr>
  <tr>
    <td class="k">Student / Teacher</td>
    <td>Everything a guest can do, plus: open documents in the secure viewer, additionally see
        <b>Restricted</b> collections, bookmark items, copy citations, keep a reading history,
        report problematic content, and use a personal dashboard. Students and teachers have
        identical repository privileges; the distinction exists for reporting/analytics.</td>
  </tr>
  <tr>
    <td class="k">Repository Staff</td>
    <td>Everything above, plus: upload, edit, archive, restrict, and replace collections; manage
        categories; view reports and the audit trail; resolve reported items. Three further
        capabilities are <b>individually grantable</b> by a Super Admin rather than automatic:
        deleting documents, deleting accounts, and resetting passwords.</td>
  </tr>
  <tr>
    <td class="k">Super Admin</td>
    <td>Full control: everything above, plus creating Staff/Super Admin accounts, granting and
        revoking the individual permissions above, and activating/deactivating any account.</td>
  </tr>
</table>

<div class="note">
  <b>Role hierarchy is enforced.</b> A Staff member can only delete Student or Teacher accounts —
  never a fellow Staff member and never a Super Admin. Every permission-gated action is also hidden
  in the interface for users who lack it, not merely rejected after they try.
</div>

{{-- ===================== 3. FEATURE CATALOG ===================== --}}
<div class="pagebreak"></div>
<h1 class="section">3. Complete Feature Catalog</h1>

<h2 class="sub">3.1 Accounts &amp; Authentication</h2>
<ul>
  <li>Self-service registration for Student and Teacher accounts. Staff and Super Admin accounts can only be created by an existing Super Admin — there is no public signup for privileged roles.</li>
  <li>Email/password login issuing a bearer token valid for 7 days (configurable) before re-login is required.</li>
  <li>Self-service password change from a user's own profile; admin-assisted password reset for any account. By deliberate design there is <b>no self-service "forgot password" email flow</b> — resets are requested from staff in person.</li>
  <li>A single password policy — minimum 8 characters including upper-case, lower-case, and a number — enforced identically everywhere a password is set (registration, self-change, admin-created accounts, admin resets), with instant in-form feedback.</li>
  <li>Login and registration are rate-limited to 5 attempts per minute, with a live "try again in N seconds" countdown once triggered. Every other endpoint is limited to 120 requests/minute as a general abuse and scraping guard.</li>
  <li>Profile picture upload (2 MB limit), displayed wherever the account appears — sidebar, admin user table, and profile page — falling back to an initials avatar when none is set.</li>
</ul>

<h2 class="sub">3.2 Collection (Thesis) Management</h2>
<ul>
  <li>Upload with full bibliographic metadata: title, author(s), adviser, year published, category/department, page count, abstract, keywords, cover image, and the PDF itself.</li>
  <li>Abstract and keywords may be left blank — the system extracts them automatically from the document's own Abstract/Keywords section (see 3.3), without ever overwriting anything a staff member typed.</li>
  <li><b>Three-tier visibility:</b> <b>Active</b> (visible to everyone including guests), <b>Restricted</b> (visible to any logged-in college account, hidden from guests), and <b>Archived</b> (hidden from everyone but retrievable by staff).</li>
  <li>Full metadata editing after upload, from a dedicated staff edit page.</li>
  <li><b>File replacement with version history.</b> Staff can swap a collection's PDF at any time (a wrong file, or a better scan). The previous file is not destroyed — it is archived and stays restorable for a configurable grace period (30 days by default), after which it is purged automatically. Any version can also be restored or permanently deleted early, on demand.</li>
  <li>Previous versions are listed with their file size, who replaced them, when, and how many days remain to restore — and each can be <b>previewed</b> before restoring, so staff can visually confirm which file is which.</li>
  <li>Restoring never loses data, even across repeated swaps: whatever file is active at the moment of restoring is itself archived first.</li>
  <li>Soft deletion — a deleted collection is recoverable at the database level, not destroyed outright.</li>
  <li>Category/department management: staff can create, rename, and delete categories, each with its own cover image.</li>
</ul>

<h2 class="sub">3.3 Digitization &amp; OCR (Optical Character Recognition)</h2>
<ul>
  <li>Handles <b>both</b> digitally-authored PDFs (with real embedded text) and <b>scanned/image-only PDFs</b> (physical pages photographed and compiled) — automatically detecting which kind each upload is.</li>
  <li>For scanned documents, the system rasterizes the pages to images and runs OCR to recover machine-readable text. For very long scans (60–150+ pages), only the first 12 and last 8 pages are processed — where the title, abstract, keywords, introduction, and conclusion live — keeping upload times reasonable.</li>
  <li>From either kind of PDF, the system locates and extracts the <b>Abstract</b> and <b>Keywords</b> sections specifically (heading-aware), rather than dumping raw text.</li>
  <li>Extracted keywords are cleaned before saving: a failed extraction that returns a paragraph of body text is discarded rather than stored as garbage, and genuine keyword lists are capped in count and term length.</li>
  <li><b>Human-reviewed metadata refresh.</b> When a file is replaced, the system re-reads the new document and compares what it finds against what is on record — flagging, for example, an abstract that the new file no longer contains. Staff review the difference and choose per field whether to apply or clear it. Nothing is ever silently overwritten.</li>
  <li>A "re-extract from current file" action lets staff re-run detection against the active document at any time.</li>
</ul>

<h2 class="sub">3.4 Search &amp; Browse</h2>
<ul>
  <li>Full-text search across title, authors, adviser, abstract, and keywords — typo-tolerant and relevance-ranked.</li>
  <li>Search never goes fully down: if the dedicated search engine is unavailable, the system automatically falls back to a database search. Results still appear; only typo-tolerance and relevance ranking are temporarily lost.</li>
  <li>Filterable by department/category and year of publication; sortable by relevance, newest, or most-viewed.</li>
  <li>Browse organized by department/category with cover images and real per-item view counts.</li>
  <li><b>Related collections</b> on each detail page, ranked by actual keyword and abstract overlap with the item being viewed — not merely "same category."</li>
  <li>A user's own recent searches feed their personal dashboard. These are private to that user and entirely distinct from the staff-only audit trail.</li>
</ul>

<h2 class="sub">3.5 Secure Reading &amp; Citations</h2>
<ul>
  <li><b>View-only PDF viewer.</b> Documents are never offered as a direct download to readers. Access is granted through a short-lived signed link generated per viewing session.</li>
  <li><b>Per-page live watermarking.</b> Every page of a document a reader opens is stamped, at the moment of viewing, with the viewing account's name and email plus a timestamp. The watermark is never baked into the stored file — it is applied per request, per reader, so a screenshot of <i>any</i> page is traceable to the person who opened it.</li>
  <li>Auto-generated <b>APA and MLA citations</b> for every collection, editable in place by staff. Readers can copy either format, and copy events are logged (which powers the Most Cited report).</li>
  <li>Bookmarking ("Favorites") for any signed-in account, with bulk select/remove/export and cover thumbnails. A bookmark whose document was later deleted shows a clear "no longer available" placeholder and cleans itself up, instead of rendering a broken card.</li>
  <li>Personal reading history, and a "Continue Reading" list on the dashboard drawn from the account's <i>own</i> reads.</li>
</ul>

<h2 class="sub">3.6 Content Moderation</h2>
<ul>
  <li>Any signed-in user can report a collection (wrong file, inappropriate content, factual problem) with a stated reason.</li>
  <li>A staff-only <b>Reported Items</b> queue to review and resolve open reports.</li>
</ul>

<h2 class="sub">3.7 Reports &amp; Analytics <span style="font-weight:normal;color:#718096">(staff and admin only)</span></h2>
<ul>
  <li>Dashboard totals: collections overall, by department, and by year.</li>
  <li><b>Most Cited</b> collections (from real citation-copy events) and <b>Most Searched</b> terms (from real search logs) — both filterable by role (student/teacher/both) and by date range.</li>
  <li><b>Users Online</b> — the number of distinct logged-in accounts active per hour of the day, filterable the same way. Guests are excluded, since they have no identity to count.</li>
  <li><b>Deduplicated usage statistics.</b> View counts represent distinct reading sessions, not raw page-opens: reopening or refreshing the same document within a 30-minute window counts once, so the numbers reflect genuine readership rather than inflated clicks.</li>
  <li>PDF export for any report, plus a separate audit-log PDF export with a custom date range.</li>
</ul>

<h2 class="sub">3.8 Audit Trail</h2>
<ul>
  <li>Every significant account and content action is recorded: registration, login/logout, password changes, profile edits, collection upload/edit/status-change/file-replace/file-restore/deletion, category changes, permission grants and revokes, account activation/deactivation/deletion, citation edits, and content reports plus their resolution.</li>
  <li>Each entry records <b>who</b> did it, <b>what</b> it affected, a human-readable description, the acting <b>IP address</b>, and <b>when</b> — searchable and exportable by staff and Super Admin.</li>
</ul>

<h2 class="sub">3.9 Preservation &amp; Integrity</h2>
<ul>
  <li><b>Fixity checking (SHA-256 checksums).</b> A cryptographic fingerprint is recorded for every stored document at upload, and again whenever a file is replaced or restored. A scheduled verification recomputes each file's fingerprint and compares it to the recorded one — detecting silent disk corruption or tampering that would otherwise go unnoticed in a single-copy archive. Mismatches and missing files are logged for staff attention.</li>
  <li><b>Automated database backups</b> — a scheduled, rotated dump of the entire database (metadata, accounts, citations, audit trail), which is what gives the stored documents their meaning.</li>
  <li><b>Retained file versions</b> — superseded documents remain restorable for a grace period before automatic purging (see 3.2).</li>
  <li><b>Soft deletion</b> — deleted records are recoverable rather than destroyed.</li>
</ul>

<h2 class="sub">3.10 Personalization &amp; Preferences</h2>
<ul>
  <li>Personal dashboard with role-appropriate statistics, a "Continue Reading" list (the account's own reads only), and a merged Recent Activity feed combining bookmarks, views, and searches in true chronological order.</li>
  <li><b>Light / Dark / System theme</b>, applied instantly and remembered across sessions, with no flash of the wrong theme on load.</li>
  <li><b>Reduce Motion</b> accessibility toggle that suppresses interface transitions and animations app-wide.</li>
  <li>Editable profile (name, email, profile picture) and a Security tab showing password change plus a read-only view of the account's own roles and granted permissions.</li>
</ul>

<h2 class="sub">3.11 Administration</h2>
<ul>
  <li><b>User management</b> — create Staff/Super Admin accounts, activate/deactivate any account, delete accounts (subject to the role hierarchy), grant/revoke individual permissions, and perform admin-assisted password resets.</li>
  <li><b>Collection management</b> — the full collection list with search and filters, restrict/unrestrict, archive/unarchive, permission-gated delete, and the file replacement and version-history tools.</li>
  <li><b>Category management</b> with cover images.</li>
</ul>

<h2 class="sub">3.12 Performance &amp; Reliability</h2>
<ul>
  <li><b>Graceful degradation.</b> No optional service can take the system down. If the search engine stops, search falls back to the database. If the cache stops, results are simply computed fresh. Health checks for both are themselves cached briefly, so an outage never adds a repeated timeout penalty to every request.</li>
  <li>Page code is loaded on demand per route rather than as one large bundle, so a first visit downloads only what that page needs.</li>
  <li>Frequently-read, rarely-changed data is cached with automatic invalidation on write, and self-heals if a cached entry is ever left incompatible with a later change.</li>
  <li>Database indexing on the fields used for browsing, filtering, and sorting, so performance holds as the collection grows into the thousands.</li>
</ul>

<h2 class="sub">3.13 Responsive &amp; Cross-Device Design</h2>
<ul>
  <li>Usable on desktop, tablet, and phone: a slide-out navigation drawer on phone widths, horizontally-scrolling tab bars and toolbars instead of clipped controls, and wrapping rather than overlapping button rows throughout.</li>
  <li>The secure PDF viewer is mobile-aware — the page-thumbnail rail closes by default on phones and the control toolbar scrolls rather than hiding controls off-screen.</li>
</ul>

<h2 class="sub">3.14 Security Hardening</h2>
<ul>
  <li>Rate limiting on every endpoint, with a much stricter limit on login and registration to blunt password brute-forcing.</li>
  <li>Authentication tokens expire automatically rather than remaining valid indefinitely.</li>
  <li>Password complexity enforced everywhere a password is set.</li>
  <li><b>Cross-site scripting (XSS) protection</b> — no user-supplied content (collection titles, account names) can execute as script in another user's browser; all dynamic text is escaped.</li>
  <li>File upload validation (type and size) on every upload surface — documents, cover images, and avatars.</li>
  <li>Security response headers (clickjacking protection, MIME-sniffing protection, referrer and permissions policies, and HTTPS enforcement in production).</li>
  <li>Role- and permission-based authorization enforced on the server for every protected action — never trusting the interface alone.</li>
</ul>

<h2 class="sub">3.15 Quality Assurance</h2>
<ul>
  <li>An automated test suite covering the system's critical paths: authentication and password policy, login rate-limiting, role-based access control and the role hierarchy, collection visibility rules for guests versus logged-in users, and the complete file-replacement, version-restore, and checksum lifecycle.</li>
</ul>

{{-- ===================== 4. TECHNOLOGY STACK ===================== --}}
<div class="pagebreak"></div>
<h1 class="section">4. Technology Stack</h1>

<p class="lead">
  Every external technology the system uses, what it does, and whether the system depends on it.
  Note the <span class="opt">OPTIONAL</span> entries — the system is deliberately built so these can
  be absent and it still runs correctly, only with reduced capability.
</p>

<h2 class="sub">4.1 Backend</h2>
<table>
  <tr><th style="width:26%">Technology</th><th>Purpose in TIPIGANAN</th><th style="width:14%">Status</th></tr>
  <tr><td class="k">PHP 8.3</td><td>The language the backend runs on.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Laravel 13</td><td>The backend framework — routing, the REST API, database access, validation, scheduling, and console commands.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">MySQL</td><td>The relational database storing all records: collection metadata, accounts, roles and permissions, citations, bookmarks, reading history, audit logs, and file-version history.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Laravel Sanctum</td><td>Token-based authentication. Issues the bearer token a user receives at login and enforces its expiry.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Spatie Laravel-Permission</td><td>The role and permission system underpinning all role-based access control.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Laravel Scout</td><td>The search abstraction layer. Lets the system talk to a search engine, and lets it fall back to the database when none is available.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">FPDI + FPDF</td><td>Reads the stored PDF and stamps the per-reader watermark onto every page at view time.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">DomPDF</td><td>Generates the PDF exports — analytics reports, audit-log exports, and this document.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Predis</td><td>The client used to talk to Redis. Pure PHP, so no compiled extension is needed.</td><td><span class="opt">OPTIONAL</span></td></tr>
</table>

<h2 class="sub">4.2 External Services</h2>
<table>
  <tr><th style="width:26%">Service</th><th>Purpose in TIPIGANAN</th><th style="width:14%">Status</th></tr>
  <tr>
    <td class="k">Meilisearch</td>
    <td>The dedicated search engine. Provides fast, <b>typo-tolerant</b>, relevance-ranked full-text search across collection metadata and extracted document text.
        <br><b>If unavailable:</b> the system automatically falls back to a direct database search. Search still works — it simply loses typo-tolerance and relevance ranking.</td>
    <td><span class="opt">OPTIONAL</span></td>
  </tr>
  <tr>
    <td class="k">Redis <span style="font-weight:normal">(or Memurai on Windows)</span></td>
    <td>An in-memory cache that stores frequently-read, rarely-changed data — the analytics dashboard, category lists — so the database is not queried repeatedly for the same result.
        <br><b>If unavailable:</b> the system detects this, stops trying, and simply recomputes the values fresh. Pages are slightly slower; nothing breaks or errors.</td>
    <td><span class="opt">OPTIONAL</span></td>
  </tr>
</table>

<div class="note">
  <b>Why this matters.</b> Both external services are genuine enhancements rather than dependencies.
  A teammate or a new deployment can run the entire system with neither installed and every feature
  still works — only search quality and page speed are reduced. This was a deliberate design goal.
</div>

<h2 class="sub">4.3 OCR / Digitization Pipeline <span style="font-weight:normal;color:#718096">(Node.js)</span></h2>
<table>
  <tr><th style="width:26%">Technology</th><th>Purpose in TIPIGANAN</th><th style="width:14%">Status</th></tr>
  <tr><td class="k">Node.js</td><td>The runtime the document-extraction pipeline runs on, invoked by the backend.</td><td><span class="req">REQUIRED<br>for OCR</span></td></tr>
  <tr><td class="k">pdf-parse</td><td>Reads embedded text out of digitally-authored PDFs, and is used to detect whether a document even has a text layer (i.e. whether OCR is needed at all).</td><td><span class="req">REQUIRED<br>for OCR</span></td></tr>
  <tr><td class="k">pdf-to-img</td><td>Converts pages of a scanned, image-only PDF into images so they can be read by the OCR engine.</td><td><span class="req">REQUIRED<br>for OCR</span></td></tr>
  <tr><td class="k">Tesseract.js</td><td>The OCR engine itself — recognizes the text inside scanned page images, making old physical theses machine-readable and searchable.</td><td><span class="req">REQUIRED<br>for OCR</span></td></tr>
</table>
<p class="lead" style="font-size:10.8px;color:#4a5568">
  If the OCR pipeline is not installed, uploads still succeed — the system simply cannot auto-fill
  the abstract and keywords, so staff type them manually.
</p>

<h2 class="sub">4.4 Frontend</h2>
<table>
  <tr><th style="width:26%">Technology</th><th>Purpose in TIPIGANAN</th><th style="width:14%">Status</th></tr>
  <tr><td class="k">React 19</td><td>The interface library. Builds the whole user interface out of reusable components and updates the page without full reloads.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Vite</td><td>The build tool and development server. Bundles the application and splits it per page so a first visit downloads only what it needs.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">React Router</td><td>Client-side navigation between pages of the application.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">Axios</td><td>The HTTP client the frontend uses to talk to the backend API, and where the auth token is attached to every request.</td><td><span class="req">REQUIRED</span></td></tr>
  <tr><td class="k">react-pdf <span style="font-weight:normal">(PDF.js)</span></td><td>Renders the PDF inside the browser for the secure, view-only reading experience — with no download, and with the watermark overlay on every page.</td><td><span class="req">REQUIRED</span></td></tr>
</table>

{{-- ================== 5. MAINTENANCE OPERATIONS ================== --}}
<h1 class="section" style="margin-top:22px">5. Automated Maintenance</h1>
<p class="lead">The system performs the following unattended, on a schedule:</p>
<table>
  <tr><th style="width:30%">Task</th><th>What it does</th><th style="width:16%">Frequency</th></tr>
  <tr><td class="k">Database backup</td><td>Dumps the full database to a timestamped, rotated backup file.</td><td>Daily</td></tr>
  <tr><td class="k">Expired file purge</td><td>Permanently deletes superseded document versions whose restore grace period has passed.</td><td>Daily</td></tr>
  <tr><td class="k">Fixity verification</td><td>Recomputes every stored document's checksum and compares it to the recorded one, flagging corruption, tampering, or missing files.</td><td>Weekly</td></tr>
</table>

{{-- ================== 6. SCOPE EXCLUSIONS ================== --}}
<h1 class="section" style="margin-top:22px">6. Deliberate Scope Exclusions</h1>
<p class="lead">
  The following are common in large public digital repositories but were <b>evaluated and
  intentionally excluded</b> — they are design decisions, not omissions:
</p>
<table>
  <tr><th style="width:34%">Excluded</th><th>Reason</th></tr>
  <tr><td class="k">Persistent identifiers (DOI / Handle)</td><td>These exist to make a work citable and resolvable <i>across</i> institutions. With no external access to the repository, there is no outside citation network to serve. The system's own stable, permanent internal links already cover every case that applies at this scope.</td></tr>
  <tr><td class="k">OAI-PMH / search-engine discoverability</td><td>These exist so outside parties can harvest or find the collection. That is directly contrary to the system's purpose of remaining internal to the college.</td></tr>
  <tr><td class="k">Self-service password reset</td><td>A deliberate policy decision: password resets are handled in person by repository staff, which suits an institution where all users are physically present.</td></tr>
  <tr><td class="k">Time-based embargo</td><td>Judged unnecessary — the Restricted and Archived visibility levels already cover the access-control needs identified.</td></tr>
  <tr><td class="k">Public / guest document access</td><td>Guests may browse and search titles and metadata, but opening any document requires an account. This is the core access-control premise of the system.</td></tr>
</table>

<p class="footer-note">
  TIPIGANAN — MDC Online Repository of Special and Rare Collections · Group 7 · Generated {{ $generatedAt }}
</p>

</body>
</html>
