# TIPIGANAN — Changes the Capstone Paper Must Reflect

**What this is.** A running checklist of system changes made *after* the last
documentation review, written so that the next review of the paper
(`Desktop\CAPSTONE\CAPSTONE-GROUP7.docx` or its successor) can be checked
against it point by point. Each entry says how the system works **now**, and
what in the paper is likely to need changing.

**How to use it when reviewing the paper.**
1. Read §1 first — it is the current, authoritative description of statuses
   and archiving. Anything in the paper that contradicts it is a finding.
2. Go through §2 entry by entry; search the paper for the terms listed under
   *Look for* and check each hit.
3. Use §3 for figures (screenshots) that no longer match the system.
4. When an entry is fully reflected in the paper, mark it `[x] reflected` with
   the date and the draft name. Do not delete entries.

**Standing rule (user decision):** the paper describes **all planned AND
completed features**. Unfinished features are *not* removed from the paper or
moved to Recommendations because they are unfinished. This file is about
making the paper *accurate*, not about trimming it.

**Baseline:** the last review was of `CAPSTONE-GROUP7.docx` on **2026-09-22**
(18 findings — see `PROJECT-STATUS.md`, "CAPSTONE-GROUP7 reviewed"). Those
findings still stand unless marked otherwise; this file lists what changed
*since*.

**Review log.**
- **2026-10-29 — the team's newer `Downloads\CAPSTONE-GROUP7-revised2m.docx` (saved 10-27 02:37, with the 10-27 tracked changes accepted and reworded; all 8 placeholders replaced by screenshots) → `Downloads\CAPSTONE-GROUP7-revised2m - UPDATED 2.docx`** for §2.18: 129 tracked revisions, 41 "[NEW]" comments, 10 screenshot placeholders (4.2.1, 4.2.2, 4.2.3a/b, 5.1, 5.2a/b, 5.3, 5.20, 5.21). The earlier `- UPDATED.docx` is superseded (left in place). Also fixed the duplicate "Table 4.4.12" (School Roster → 4.4.13).
- **2026-10-27 — `Downloads\CAPSTONE-GROUP7-revised2m.docx` (the team's newer draft, 108 pages; most of §2.1–2.16 already worked in by the team: use cases 6, 14, 15, 18, 19, 20, Figs 5.6/5.7/5.11/5.13/5.16, test count 196, year) → `Downloads\CAPSTONE-GROUP7-revised2m - UPDATED.docx`.** At the user's request the changes were **written into the copy as tracked changes** (author "Claude (update)", 136 revisions, 62 comments each starting "[NEW]") instead of only commented, with **placeholder pictures** ("NEW SCREENSHOT HERE" — right-click › Change Picture) for Figs 4.2.2, 4.2.3a/b, 5.2a/b, 5.3 and the new 5.20 School Records / 5.21 Starting Password Reminder. Written in: §2.17 throughout (scope, limitation, Ch III School Student Records System, UC1/13/20, **new UC21 Import School Records**, Table 4.4.1 two rows, **new Table 4.4.13 school_roster**, 4.5, 4.6 step 3, Figs 5.2/5.3/5.14, recommendation); §2.1 scope sentence ("soft delete" for archiving corrected); §2.5 Fig 5.7/5.16 Item Details; §2.9 Desktop/Mobile; §2.14/2.15 Table 4.4.3 adviser/year + OCR year in Ch III; §2.16 original_checksum row + Fig 5.13; test count 225/830. Comments only: Fig 4.1.1 (add UC21) and Fig 4.3.1 ER diagram. Scripts: `scratchpad/rev/update.ps1` + `updates.json`. Mark the entries reflected once the team accepts the changes.
- **2026-10-20 — `DesktopCAPSTONE-GROUP7-revised2m.docx` reviewed** (103 pages, up from 74) → `DesktopCAPSTONE-GROUP7-revised2m - REVIEWED.docx`, 26 comments (green = new, yellow = still open). **Most of the 30 earlier comments were acted on:** staff-privileges sentence, background reading, the seven missing technologies, Manage User Accounts, the File a Scanned Thesis and Register Account use cases, the whole data dictionary (users, categories, enum values, OCR columns, bookmarks wording, settings key, signed_url_tokens), Chapter V rewritten with **51 new screenshots** and six new figures (User Management, Edit Item, Bookmarks, My Profile, Landing Page Management, Active Term), the 5.13 caption typo, Recommendations filled, appendices restored. **Partly reflected:** §2.2 (Fig 5.6 lists the status filter), §2.4 (Fig 5.16 — not yet the use case), §2.6 (Fig 5.7 archived notice), §2.8 (Fig 5.13 View/Discard — not yet the use case), §2.10 (CSV). **Still open:** §1 archiving in the scope, §2.1/2.3 use cases 14 and 15, §2.5 Item Details captions, §2.9 Desktop/Mobile, §2.11 test count (196), §2.12 Terms checkbox (now *in* the paper and the screenshot), §2.13 bulk actions, §2.14 adviser, §2.15 year, §2.16 duplicates; ER diagram and Fig 4.2.2 not re-exported.
- **2026-10-08 — `CAPSTONE-7-CURRENT.docx` reviewed** → `CAPSTONE-7-CURRENT - REVIEWED.docx`
  (30 comments; green highlight = new since the last review, yellow = still
  open from it). The draft's text was almost unchanged from CAPSTONE-GROUP7
  (spacing, CVs, Appendices A and B removed, the lone "a." under
  Recommendations deleted) and **all 28 images were byte-for-byte identical**,
  so all 18 earlier findings were carried forward, refreshed to current facts
  (165 tests, single-step registration by default). Every §2 entry below was
  raised in that review; none is reflected in the paper yet. Comment
  scripts: `scratchpad/rev/` of that session (`annotate.ps1`,
  `findings.json`, `where.ps1` to check where each comment landed).

---

## 1. How statuses and archiving work now (authoritative)

Every item in the repository has exactly one **status**. The status decides
who can find it (Browse, search, bookmarks), who can open its detail page, and
who can read the PDF. The rule is defined once in the code
(`Thesis::visibleStatusesFor`) and every part of the system uses it.

| Status | Guest (not signed in) | Student / Teacher | Staff / Super Admin |
|---|---|---|---|
| **Active** | find, open, read | find, open, read | find, open, read |
| **Restricted** | cannot see at all | find, open, read | find, open, read |
| **Archived** | cannot see at all | cannot see at all | find, open, **read** |

**Archiving an item** (staff, from Collection Management → Actions → Archive Item):
- Hides it from students, teachers and guests everywhere: Browse, search,
  department counts, the detail page (they get "not available") and the PDF
  reader.
- Does **not** delete anything. The record, the PDF, its file-version history,
  citations, view counts and everyone's bookmarks are all kept.
- Staff still see it in Browse and search, marked with an **Archived** label and
  a grey, dashed card; they can filter to it with the Browse **Status** filter;
  its **Item Details** page shows an "This item is archived" banner, a greyscale
  cover labelled ARCHIVED, and staff can still read it.
- Readers who had bookmarked it keep the bookmark. It shows on My Bookmarks as
  **"Currently unavailable"** (no title or details, under an *Unavailable* tab).

**Restoring** (staff, Collection Management → change status back to Active or
Restricted): the item reappears everywhere at once, including on the
bookmarks of everyone who had saved it — nobody has to bookmark it again.

**Restricted** is not a lesser archive: it is "members only" — hidden from the
public but open to every signed-in account.

**Deleting** is separate from archiving: Super Admin only, a soft delete
(recoverable at database level). A deleted item's bookmarks show
**"Removed from the repository"**.

**Search uses the same rule.** Staff can narrow Browse *and* search to one
status, or choose "All, including archived". Readers have no such control and
never get archived results, even by typing `?status=archived` into the address.

> Paper-wording pitfalls: do **not** say archived items are "hidden from
> everyone", "deleted", or "only retrievable by staff on request" — all three
> are now wrong. Do not describe Restricted as "partially archived".

---

## 2. Changes since the 2026-09-22 review

Each entry: what changed → where the paper probably mentions it → what to do.
*Look for* lists search terms to use in the .docx.

### 2.1 Archived items: who can see and read them  *(2026-10-07)*
- **Now:** as §1. Before, archived was described (and partly behaved) as
  "hidden from everyone"; staff could open an archived item's page but got an
  error when reading it; search hid archived items from staff.
- **Paper likely affected:** Chapter I scope/limitations (if it describes
  access levels); system features / functional requirements; use cases for
  managing collections and for reading; any table of roles and permissions;
  data dictionary entry for `theses.status`.
- **Do:** describe the three statuses with the §1 table; state that staff can
  read archived items and readers cannot; list the enum values
  `active`, `restricted`, `archived` in the data dictionary (the 2026-09-22
  review already flagged that enum values are unlisted).
- *Look for:* archive, archived, hidden, restricted, status, visibility.
- [ ] reflected

### 2.2 Browse: Status filter and status labels for staff  *(2026-10-07)*
- **Now:** staff and super admins see a **Status** label on every Browse card
  (Active / Restricted / Archived) and archived cards are greyed and dashed.
  Restricted cards (staff view) carry a red stripe down the left edge with a
  faint red tint, but are not dimmed, because they are live for every
  signed-in reader.
  The filter sidebar has a **Status** group — *All, including archived* /
  *Active only* / *Restricted only* / *Archived only* — which applies to both
  browsing and searching (a dropdown on phones). Department counts follow it.
  Students and guests see none of this.
- **Paper likely affected:** Browse/Search feature description; the Browse
  screenshot in Chapter V; any use case for searching that lists filters
  (department, year — now also status, for staff).
- **Do:** add the staff-only Status filter and labels; note readers do not see
  them.
- *Look for:* browse, filter, search, department, year.
- [ ] reflected

### 2.3 Search follows the visibility rule, and an index leak was closed  *(2026-10-07)*
- **Now:** search returns exactly what the caller may see (§1), and accepts the
  same status filter as browsing. The search engine's copy of each item's
  status is updated whenever an item is archived, and results are re-checked
  against the database, so a stale search index can never show a reader
  something they may not see.
- **Was:** the Archive button left the search index saying "active", so
  **guests could find archived items by searching** (reproduced live, fixed).
- **Paper likely affected:** Meilisearch / search description, security or
  access-control sections, testing results if they list fixed defects.
- **Do:** if the paper says search shows "only active items to guests and
  active + restricted to members", add staff + archived. Optionally list the
  leak under defects found and fixed during testing.
- *Look for:* Meilisearch, search, index, fallback.
- [ ] reflected

### 2.4 Bookmarks survive archiving  *(2026-10-07)*
- **Now:** a bookmark is never removed by the system. If its item is archived,
  the bookmark shows as **Currently unavailable** (no title or details) under an
  **Unavailable** tab, with no Read button; when staff restore the item it
  reappears on the same bookmark. Deleted items show **Removed from the
  repository**. Readers cannot bookmark an item they cannot see.
- **Was:** the bookmarks list kept showing an archived item's title, authors
  and cover to readers (a leak), and opening it **deleted the bookmark**, so it
  never came back after a restore. The old feature text said a bookmark to a
  deleted item "cleans itself up" — that is no longer true.
- **Paper likely affected:** bookmark feature description, the bookmarks use
  case, the My Bookmarks screenshot.
- **Do:** replace "cleans itself up" wording with the above.
- *Look for:* bookmark, favorite, favourite, saved, no longer available.
- [ ] reflected

### 2.5 "Thesis Detail" page renamed to "Item Details"  *(2026-10-07)*
- **Now:** the detail page is titled **Item Details** for everyone (guests,
  readers, staff). The breadcrumb, the reading notice ("This item is available
  for online reading only…"), the **Related Items** heading and its messages
  say "item". Reason: the repository holds more than theses — Special
  Collections, Institutional Publications, Special Boholano Creations.
  **Only this page was renamed;** other screens still say "thesis" (user
  decision), and route/component names are unchanged.
- **Paper likely affected:** Chapter V figure and caption for the detail page;
  any text that names the screen "Thesis Detail(s)" or "Related Theses".
- **Do:** rename the screen in captions and text to "Item Details". Decide as a
  team whether the paper's general wording ("thesis") should move toward
  "item" / "collection item"; the system currently mixes both.
- *Look for:* Thesis Detail, Thesis Details, Related Theses, detail page.
- [ ] reflected

### 2.6 Archived look on the Item Details page  *(2026-10-07)*
- **Now:** for an archived item (staff only): a banner — "This item is
  archived. Students and guests cannot find or open it; only staff can. To
  publish it again, change its status in Collection Management." — grey dashed
  panel, greyscale cover labelled **ARCHIVED**, solid Archived badge with
  "Hidden from students and guests".
- **Paper likely affected:** Chapter V only if it shows or describes an
  archived item's page.
- *Look for:* archived, detail.
- [ ] reflected

### 2.7 Login and Register pages  *(2026-10-07)*
- **Now:** "Back to Home" is a pill button pinned to the top-left corner (on
  phones too). The blue panel no longer lists system features ("Secure
  view-only PDF access", "Powerful multi-field search", "Personal bookmark
  library", "Multi-department repository"). Login says **"Sign in to:"** read the
  full thesis (not just the abstract), see restricted collections, save to
  bookmarks, report a problem. Register first states **who can register**
  ("current Mater Dei College students and teachers, using your school ID
  number"), then the same four points.
- **Paper likely affected:** Chapter V login/register figures (already outdated
  per the 2026-09-22 review).
- *Look for:* login, sign in, register, registration.
- [ ] reflected

### 2.8 Scan inbox: bounded list, View, and a confirmation before discarding  *(2026-10-06)*
- **Now:** the "scanned files waiting" list sits in a fixed-height scrolling
  box with a true total ("showing the newest N" past the cap of 100); each scan
  has labelled **View** (opens the PDF) and **Discard** buttons; Discard asks
  for confirmation in a dialog that names the file, shows its size, pages and
  scan time, warns it **cannot be recovered**, and offers "View it first".
  Upper-case `.PDF` files are now listed (they were invisible).
- **Paper likely affected:** the "File a Scanned Thesis" use case — which the
  2026-09-22 review found **missing** from the paper; the upload screenshot.
- **Do:** when writing that use case, include View and the discard
  confirmation.
- *Look for:* scan, scanner, inbox, upload.
- [ ] reflected

### 2.9 Every screen works on phones and tablets  *(2026-10-06)*
- **Now:** management tables (Collections, Users, Audit Logs, Categories,
  Reported Items, Reports' Most Cited) turn each row into a labelled card on
  phones; on tablets and laptops the Actions column stays pinned at the right
  edge. Charts, number tiles, the top bar, the upload page and the abstract
  were all fixed for small screens. (A side effect, Actions menus being covered
  by the rows below, was fixed on 2026-10-07.)
- **Paper likely affected:** non-functional requirements (usability /
  responsiveness / device support); testing (compatibility testing).
- *Look for:* mobile, responsive, device, phone, tablet, compatibility.
- [ ] reflected

### 2.10 Smaller corrections that can affect wording  *(2026-10-06)*
- Audit Logs export is a **CSV** with **seven** columns (the screen used to say
  "Export PDF includes Timestamp, User, Action, and Description").
- Reported Items' "Resolved By" shows the ID number when an account has no name.
- *Look for:* export, PDF export, audit log, resolved by.
- [ ] reflected

### 2.18 No self-registration: accounts are activated at the library  *(2026-10-29)*
- **Now:** there is **no Register page**. A student or teacher goes to the
  library with their school ID card; staff open **School Records → Activate an
  Account**, enter the ID number, see the name the school's list holds for it
  and confirm it against the ID card; then the person types **their own** email
  and password on the staff computer. Only IDs on the imported list can be
  activated; the role comes from the list (staff / Super Admin rows become
  ordinary accounts, promoted in User Management). Every staff member can
  activate by default (permission `activate_accounts`). The **starting password
  (KEN_10001) is gone**, and so is its limitation. Accounts made under the old
  self-registration must be activated again (they keep their bookmarks).
- **Paper affected:** objective 1.3 ("must register"); scope (sign-up wording);
  the starting-password limitation; Ch III School Student Records System;
  **Use Case 20 Register Account → Activate Account** (actor: Repository Staff);
  Use Cases 13 (password reminder after a reset; not-activated refusal) and 21
  (activated, not registered); use case diagram; Figures 4.2.1–4.2.3 and
  5.1–5.3, 5.14, 5.20, 5.21; data dictionary `users.needs_activation`,
  `must_change_password` (now only after a reset), `school_roster.first_name`;
  4.5 test count **210 (789 assertions)**; 4.6 step 3; the one-time-code
  recommendation (replaced by a confirmation email).
- *Look for:* register, registration, sign up, starting password, Get Started.
- [ ] reflected (written into `- UPDATED 2.docx` as tracked changes)
- **Supersedes the registration and starting-password parts of §2.17 below.**

### 2.17 Access limited to the school's list (CSV), with a starting password  *(2026-10-27)*
- **Now (built; switched on by setting the driver to csv):** the Super Admin
  imports a CSV from the school's database (ID number, first name, last name,
  role) on a new **School Records** page, with a preview first. Only ID numbers
  on the list can register; the person enters their email and a **starting
  password** — first three letters of the first name, "_", ID number, e.g.
  KEN_10001, capitals ignored — and is then reminded on every page to change
  it. Only accounts on the list can sign in or keep using the system, every
  role included. Staff and Super Admin rows register as ordinary accounts and
  are promoted by the Super Admin.
- **Paper likely affected:** Chapter I scope (access limited to the school's
  list); Register Account use case — the ID is checked against the imported
  list, the Guest enters the starting password, and **the person's name is not
  shown** during registration; Authentication use case (off-list accounts
  refused; reminder to change the starting password); a new use case
  **Import School Records** (Super Admin); data dictionary — new table
  `school_roster` (id_number char 5 PK, first_name, last_name, role,
  imported_at) and `users.must_change_password`; Chapter III, the "School
  Student Records System" entry can say the records arrive as a CSV export;
  a Chapter V figure for School Records.
- **Re-importing (same day):** importing a new list **switches off** every
  account whose ID is not on it (signed out, cannot sign in; password kept).
  Accounts still on the list carry on with their own passwords. An account
  switched off this way **is switched back on** when a later list has it again.
  Accounts deactivated by staff by hand stay off. Paper: the Import School
  Records use case (main flow + "accounts not on the new list are switched
  off" / "returning accounts are switched back on"); Manage Users use case
  (an account off the list cannot be activated by hand); data dictionary
  `users.deactivated_by_roster`.
- *Look for:* register, ID verification, school records, password.
- [ ] reflected

### 2.16 Duplicate uploads are prevented  *(2026-10-20)*
- **Now:** the exact same file cannot be filed twice — on upload, from the scan
  inbox, or as a replacement file — and staff are pointed to the item that
  already has it (an archived one: "restore it instead"). A different file of
  what looks like the same work — matching title (ignoring capitals,
  punctuation and subtitle) **and** at least one author in common — brings up a
  warning while typing and an "Upload anyway?" confirmation; staff decide.
  Deleted items do not count.
- **Paper likely affected:**
  - Manage Thesis Records / upload use case and the suggested **File a Scanned
    Thesis** use case: add alternative flows — identical file refused; possible
    duplicate warned, confirm to continue; a refused scan stays in the inbox.
  - Data dictionary, Table 4.4.3: new column **`original_checksum` char(64)** —
    "fingerprint of the file as uploaded, used to refuse the same file twice";
    and `checksum`'s description could note it is taken after the file is
    prepared for viewing (used for the weekly integrity check).
  - Figure 5.13 / upload steps: mention the duplicate warning.
  - Testing (4.5): a defect-style finding worth one line — the existing
    checksum could not detect duplicates because the viewer preparation step
    rewrites files non-repeatably.
- *Look for:* duplicate, checksum, upload, scan inbox.
- [ ] reflected

### 2.15 "Year Published" is now "Year" — optional, and read from the document  *(2026-10-20)*
- **Now:** the field is called **Year** everywhere, because much of the
  collection (special collections, institutional records, local creations) was
  never published. It is **optional**: left blank at upload, the system reads it
  from the document's front matter — a cover date ("December 2023"), an academic
  year ("A.Y. 2023–2024", taking the later year), a copyright line, or the year
  alone on a line — the same way it fills the abstract and keywords, never
  overwriting a year staff typed. An item whose document states no year has
  none; its citations say **"n.d."** (no date), and Reports groups it under
  "No year".
- **Paper likely affected:**
  - Data dictionary, Table 4.4.3: `year_published year` → **`year` year,
    nullable**, description e.g. "Year of the item, if known; read from the
    document when left blank".
  - Objectives (1.3): "Organize content by category and **date published**" →
    "by category and year".
  - Figure 5.13 / upload steps; Figure 5.7 / Item Details ("Shows the adviser,
    **year**, department…" — only when present); Figure 5.6 / Browse filters
    ("filter results by department and year" is already right).
  - Chapter III, Tesseract.js / OCR description: it now also recovers the year,
    not only the abstract and keywords.
  - Citations (1.3 objective on APA and MLA): mention "n.d." for undated items.
- *Look for:* published, year published, date published, publication year.
- [ ] reflected

### 2.14 Adviser is optional  *(2026-10-12)*
- **Now:** an item can be uploaded or edited with no adviser (special
  collections and institutional publications usually have none); the details
  page shows the Adviser line only when there is one.
- **Paper likely affected:** data dictionary (Table 4.4.3, `adviser varchar
  255` — now **nullable**, description e.g. "Adviser's name, if the item has
  one"); Figure 5.13 / upload steps ("Provides fields for author, adviser, year,
  and category" — mark adviser optional); Figure 5.7 / Item Details steps
  ("Shows the adviser, year, department…" — only when present).
- *Look for:* adviser.
- [ ] reflected

### 2.13 Collection Management: bulk actions for every status  *(2026-10-12)*
- **Now:** ticking rows enables a **"With N selected"** menu: Set Active,
  Restrict to Members, Archive, and Delete Permanently (only for accounts with
  the delete permission). Each asks for confirmation with the count and says
  who will see the items afterwards; items already in that status are left
  alone; the page reports how many changed and any that failed, with the
  reason. Changing page or filters clears the selection.
- **Was:** a single "Archive Selected" button.
- **Paper likely affected:** use case 6 (Manage Thesis Records) — add a bulk
  sub flow; Figure 5.11 and its steps ("Allows options to view, edit, archive,
  and delete records").
- *Look for:* archive selected, bulk, selected.
- [ ] reflected

### 2.12 Register: no Terms and Privacy Policy checkbox  *(2026-10-12)*
- **Now:** the registration form no longer has "I agree to the Terms and
  Privacy Policy". It was removed because the library has no such documents yet
  and both links went nowhere. It will return, with real links, once they exist.
- **Paper likely affected:** Figure 5.2 steps, Figure 4.2.3, the Register
  Account use case (when written), any objective or limitation mentioning user
  agreement.
- **Do:** do not describe an agreement step. If the team wants to keep it as a
  *planned* feature (see the standing rule), describe it as planned, pending
  the library's terms and privacy policy.
- *Look for:* terms, privacy, agree.
- [ ] reflected

### 2.11 Numbers that have moved
- **Automated tests: 165** (the paper said 132; the 2026-09-22 review noted 148).
- Bookmark-related and visibility tests are in `ThesisVisibilityTest` (22) and
  `BookmarkVisibilityTest` (5).
- [ ] reflected

---

## 3. Figures (screenshots) that no longer match the system

The 2026-09-22 review found **every Chapter V screenshot byte-for-byte
identical to the previous draft**, i.e. all predate the ID-number change. In
addition to that, these now differ again (desktop):

| Screen | What differs now |
|---|---|
| Login | corner Back to Home button; "Sign in to:" panel |
| Register | corner Back to Home button; who-can-register note + panel |
| Browse (as staff) | status labels, archived cards, Status filter |
| Item Details (was Thesis Detail) | new name; "Related Items"; archived look for staff |
| My Bookmarks | Unavailable placeholder and tab (if one is shown) |
| Upload Thesis | scan inbox list with View / Discard; discard confirmation |
| Collection / User / Category Management, Reported Items | named Actions menu (since 08-11); pinned Actions column |
| Audit Logs | "Details" button; CSV export note |

---

## 4. Known gaps still open (describe honestly, or as planned)

- **Reading history** on My Profile still lists titles of items the reader
  opened before they were archived (its links then say "not available"). The
  last place a reader can still see an archived title. Not yet changed.
- **Users Online chart** omits hours with no activity instead of drawing them
  empty.
- **ID-number reissue / revoke sync** with the school roster: designed,
  on hold.
