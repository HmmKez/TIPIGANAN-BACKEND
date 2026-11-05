# TIPIGANAN — UI Changes Log

**What this is.** Every change to what users see or do on screen, one entry
per change, in the order made. Kept at the user's request so a list of UI
changes for any period can be produced later.

**Period opened 2026-10-12**, starting with the removal of the Terms and Privacy
Policy checkbox on the Register page. Changes before that date are recorded in
`PROJECT-STATUS.md` §3 (changelog) and summarised in `PAPER-CHANGES.md` §2.

**Entry format.** Date · screen(s) · who sees it · what changed (before → now)
· commit (filled in once pushed). Newest at the bottom. Add an entry for every
UI change, however small; behaviour-only changes with no visible effect belong
in `PROJECT-STATUS.md` instead.

---

## 2026-10-12

### 1. Register — "I agree to the Terms and Privacy Policy" checkbox removed
- **Screen:** Register (`/register`), both the ordinary single-step form and
  the ID-verified two-step form (they share the details step).
- **Who sees it:** everyone registering.
- **Before:** a required checkbox, "I agree to the Terms and Privacy Policy",
  whose two links went nowhere (`href="#"`); submitting without it showed
  "You must agree to the Terms and Privacy Policy."
- **Now:** no checkbox and no message; the password fields are followed
  directly by Create Account.
- **Why:** the library has no terms or privacy policy yet. To be restored, with
  real links, once those documents exist. The server never checked it.
- **Commit:** frontend `afe9750`

### 2. Browse — restricted items marked for staff
- **Screen:** Browse (`/browse`), result cards.
- **Who sees it:** Staff and Super Admin only. Students, teachers and guests
  see restricted items as ordinary cards.
- **Before:** a restricted item looked like an active one apart from its small
  "Restricted" label.
- **Now:** a 4 px red stripe down the card's left edge (the Restricted badge's
  colour) fading into a faint red tint. Not dimmed — a restricted item is live
  for every signed-in reader. (Archived cards keep their grey, dashed look;
  active cards are unchanged.)
- **Commit:** frontend `afe9750`

### 3. Collection Management — "Archive Selected" became a "With selected" menu
- **Screen:** Collection Management (`/admin/collections`), the bar above the
  table.
- **Who sees it:** Staff and Super Admin. "Delete Permanently" only for
  accounts with the delete permission (the Super Admin by default).
- **Before:** one button, "Archive Selected".
- **Now:**
  - A **"With N selected"** menu (same style as each row's Actions menu):
    **Set Active**, **Restrict to Members**, **Archive**, and, below a divider
    in red, **Delete Permanently**.
  - Before anything is ticked it shows **"With selected"**, greyed out and
    disabled.
  - Each choice opens a confirmation giving the count and saying who will be
    able to see the items afterwards; items already in that status are left
    alone and the dialog says how many. If all ticked items already have that
    status, no dialog appears — just a note.
  - After the action, a **green notice** above the table says what changed
    ("1 item archived. 1 already was — left as it was.") and an **orange
    notice** lists any items that could not be changed, with the reason.
  - Ticks are **cleared when the page or a filter changes**.
- **Side effect on all Actions buttons:** a disabled Actions-style button now
  looks disabled (faded, "not allowed" cursor, no hover colour). Only the bulk
  button is ever disabled today.
- **Commit:** frontend `afe9750`

### 4. Upload Item and Edit Item — Adviser is optional
- **Screens:** Upload Item (`/admin/upload`) and Edit Item
  (`/admin/theses/:id/edit`); Item Details (`/theses/:id`).
- **Who sees it:** Staff and Super Admin (forms); everyone (Item Details).
- **Before:** Adviser was required (red asterisk); the form would not submit
  without it, so items with no adviser — special collections, institutional
  publications — needed an invented one. Item Details always showed an
  "Adviser:" row.
- **Now:** no asterisk; a hint under the box reads "Optional — leave blank if
  the item has no adviser (e.g. special collections)". On Edit, clearing the
  box removes the adviser. Item Details shows the "Adviser:" row only when
  there is one. (Browse, dashboard and Collection Management already hid an
  empty adviser.)
- **Commit:** frontend `afe9750`, backend `c40db5e` (database migration — run `php artisan migrate`)

## 2026-10-20

### 5. "Year Published" → "Year", optional, and filled in from the document
- **Screens:** Upload Item, Edit Item, Item Details, Browse (sidebar filter),
  Collection Management, Reports & Analytics ("Items by Year"), dashboards,
  My Bookmarks (incl. export), Reading History.
- **Who sees it:** everyone (labels and Item Details); Staff and Super Admin
  (forms, Collection Management, Reports).
- **Before:** "Year Published", required (red asterisk), pre-filled with the
  current year on Upload; Browse's filter was "Publication Year"; Item Details
  always showed "Year Published:".
- **Now:**
  - The label is **"Year"** everywhere; Browse's filter heading is "Year".
  - On Upload the box is **blank by default and optional**, with the hint
    "Optional — leave blank to detect it from the PDF, like the abstract". On
    Edit: "Optional — if blank, it is filled in from the PDF when the document
    is read". While a document is being read, Edit fills the year in by itself
    once reading finishes (only if the box is still empty).
  - The Upload page's live **citation preview shows "(n.d.)"** until a year is
    entered, then follows it.
  - Edit's "Detected in current file" review lists **Year** with "Use detected"
    when the file states a different year (never "Clear").
  - Item Details shows "Year:" only when the item has one.
  - Reports' "Items by Year" chart has a **"No year"** bar for items without one.
  - Old Browse links using the previous parameter name keep working.
- **Commit:** _(not yet pushed)_

### 6. Upload Item — duplicate warning, refusal and "Upload anyway?"
- **Screens:** Upload Item (`/admin/upload`); Edit Item's Replace File.
- **Who sees it:** Staff and Super Admin.
- **Before:** nothing stopped the same item being uploaded again.
- **Now:**
  - Under **Author(s)**, an amber box **"Possible duplicate — already in the
    repository:"** appears a moment after typing stops, when the title matches
    an existing item and at least one author is shared. Each match shows its
    title (opens in a new tab), year, authors and a status badge if restricted
    or archived, with "If this is a different item, you can still upload it."
  - Pressing **Upload Item** on such an item opens **"Upload anyway?"** listing
    the matches; **Upload anyway** goes ahead, **Cancel** returns to the form.
  - If the PDF itself is already in the repository, the upload is refused with
    an orange message — "This exact file is already in the repository as
    "…" (year)." — and an **Open it** link (for an archived item: "restore it
    from Collection Management instead"). A scan chosen from the inbox stays
    there.
  - Replace File on Edit Item shows "This is already the current file of this
    item" or "That file belongs to another item: …".
- **Also fixed:** the Upload page's MLA preview for an item with no year ended
  "n.d.." — now "n.d.".
- **Commit:** _(not yet pushed)_

## 2026-10-27

### 7. Register, sign-in and a new School Records page — the school list (CSV)
- **Screens:** Register (`/register`), Login, every signed-in page (reminder
  bar), My Profile (Security tab), new **School Records** (`/admin/school-records`).
- **Who sees it:** registrants and everyone signing in (when the school list is
  in use: `SCHOOL_DIRECTORY_DRIVER=csv`); Super Admin (School Records).
- **Before:** registration asked for a self-chosen password and confirmation;
  no list of allowed people.
- **Now:**
  - **Register, step 1:** the ID number must be on the school's list
    ("We could not find that ID number…" otherwise). **Step 2** shows "ID
    number confirmed · Registering as a Student" — never the person's name —
    and asks for Email and a **Starting Password** (placeholder "e.g.
    KEN_10001", with the rule explained; capitals don't matter). No Confirm
    Password field. A wrong one: "That starting password is not right…".
  - **Login:** an account not on the list: "Your ID number is not on the
    school's list of current students and staff, so you cannot sign in…".
    Someone signed in who is removed from the list is signed out.
  - **Reminder bar** at the top of every signed-in page while the starting
    password is still in use: "You're still using your starting password.
    Change it to one only you know." — **Change password** (opens My Profile
    on the Security tab) and **Later** (hides it for the browser tab). It
    disappears once the password is changed. A password reset by staff brings
    it back.
  - **School Records** (Administration menu, Super Admin only): the current
    list with counts by role, when and by whom it was imported, accounts not on
    the list; **Choose File → Check file** shows the counts, rows that will be
    skipped and why, a block if your own ID is missing, a confirm checkbox if
    the list is much smaller, and the accounts that would lose access;
    **Import this list** asks "Replace the school list?".
  - **Re-importing** (added the same day): the Check file preview says which
    existing accounts **will be switched off** and, new, which accounts an
    earlier list switched off **will be switched back on** (green box). The
    "Replace the school list?" confirmation adds "N accounts not on it will be
    switched off". The result reads e.g. "School list imported: 14 people. 1
    account not on it switched off."
  - **Login** for a switched-off account: "Your ID number is not on the
    school's list of current students and staff, so your account has been
    switched off…".
  - **User Management:** a switched-off account shows "Not on school list"
    under its Deactivated status. Activating it while it is off the list is
    refused with an explanation.
- **Side effect everywhere:** any disabled button now looks disabled (faded,
  "not allowed" cursor). Before, disabled buttons looked clickable.
- **Commit:** _(not yet pushed)_

### 8. Login — show/hide password eye
- **Screen:** Login (`/login`).
- **Who sees it:** everyone signing in.
- **Before:** the password box only ever showed dots.
- **Now:** an eye button inside the password box, on the right. Clicking it
  shows the password and turns into an eye with a slash; clicking again hides
  it. Hidden by default. Works in light and dark mode and on phones. (Edge's
  own built-in eye is hidden so only one shows.)
- **Commit:** _(not yet pushed)_

### 9. School Records — table of ID numbers on the list
- **Screen:** School Records (`/admin/school-records`), Super Admin only.
- **Before:** only the total and the counts by role; no way to see who is on
  the list.
- **Now:** a new panel **"ID numbers on the list"** between Current list and
  Import, with "N registered · N not yet" in its header, a **search by ID
  number** box, a filter (Registered and not yet / Registered / Not registered
  yet), and a table with two columns: **ID number** and **Account** — a green
  "✓ Registered" or a grey "Not registered yet". 25 per page. **No names or
  roles are shown** (by the user's choice; the first name is also half of the
  starting password).
- **Commit:** _(not yet pushed)_

## 2026-10-29

### 10. No Register page — accounts are activated at the library
- **Screens:** Register (removed), Login, Landing page, guest top bar, School
  Records (`/admin/school-records`), the reminder bar, User Management
  (unchanged, but accounts now arrive from activation).
- **Who sees it:** everyone; staff (Activate an Account); Super Admin.
- **Before:** a public Register page (ID number, then email and the starting
  password KEN_10001); "Get Started" / "Create Free Account" / "Register"
  buttons; School Records for the Super Admin only.
- **Now:**
  - **Register page gone.** `/register` opens the sign-in page.
  - **Login:** "New to TIPIGANAN? Create an account" → "No account yet? Visit
    the library with your school ID card to have it activated." An account
    from the old registration that has not been activated gets: "Your account
    needs to be activated at the library before you can sign in…".
  - **Landing page:** the top-right Get Started button and the hero's Get
    Started are now **Sign In**; the bottom section reads "Current MDC students
    and teachers can read the full collection. To get an account, visit the
    library with your school ID card and the staff will activate it for you."
    with Sign In and Browse Collections; the footer's Register link is gone.
    **Guest top bar:** Sign In only.
  - **School Records → Activate an Account** (new panel at the top; every staff
    member sees School Records in the Administration menu now). Steps shown as
    ① ID number ② Confirm the name ③ They set their password:
    1. ID Number box + **Look up**.
    2. A card: "Name on the school's list for 10004 — **Niño Bautista** — Will be
       activated as a Student" (+ a note for an earlier account, or "the list
       says Staff; give that role in User Management afterwards"), the checkbox
       "I've checked their school ID card, and this name matches the person in
       front of me.", **Continue** (disabled until ticked).
    3. "**Turn the screen to Niño.** They type their own email and password — you
       don't need to see it." Email, Password and Confirm Password (both with
       the eye), **Activate account**. Errors show under the field; the
       password boxes empty after each attempt.
    4. Green "Account activated for Niño Bautista (10004)…" + **Activate another
       account**. **Start over** in the panel header at any step.
  - Staff see only that panel; the Super Admin also sees Current list, the ID
    numbers table and Import.
  - **ID numbers on the list:** "Registered / Not registered yet" → **Activated /
    Not activated yet** (header "N activated · N not yet", filter "Activated and
    not yet / Activated / Not activated yet").
  - **Reminder bar** (only after a staff password reset now): "Your password was
    **set by the library**. Change it to one only you know."
- **Commit:** _(not yet pushed)_

### 11. School Records — the ID numbers table pages properly
- **Screen:** School Records → ID numbers on the list (Super Admin).
- **Before:** 25 per page, and the page controls only appeared past 25, so a
  15-person list showed no pagination at all.
- **Now:** **10 per page**, with a line under the table that is always shown —
  "Showing 1–10 of 15 ID numbers" — and, when there is more than one page,
  numbered page buttons (‹ 1 … 4 5 6 … 20 ›, the same style as Browse).
  Searching or filtering goes back to page 1.
- **Side fix everywhere the pager is used (Browse too):** the current page's
  button no longer turns blank while the pointer is on it. The hover colour
  covered its white number.
- **Commit:** _(not yet pushed)_

### 12. User Management — search by ID number, and "Needs activation"
- **Screen:** User Management (`/admin/users`).
- **Before:** the search box ("Search by name or email…") only filtered the 15
  users already on the page, so anyone on another page could not be found, and
  an ID number only matched accounts that had no name.
- **Now:** "**Search by ID number, name or email…**" searches every account on
  the server: an ID number from its start ("100" finds 10001, 10002…), a name
  or an email anywhere in it, any capitals. It waits for a short pause in
  typing and goes back to page 1.
- **Only activated accounts are listed** (changed the same day, user's choice).
  An account from the old self-registration that still has to be activated at
  the library no longer appears here — it used to look simply "active", which
  was confusing. School Records lists it as "Not activated yet", and it shows up
  in User Management as soon as staff activate it.
- **Commit:** _(not yet pushed)_

## 2026-10-30

### 13. User Management — Reset Password: show/hide eye
- **Screen:** User Management → Actions → Reset Password (staff with the
  reset permission, and the Super Admin).
- **Before:** New Password and Confirm Password only ever showed dots.
- **Now:** each box has its own eye button **on the right, inside the box**,
  the same as on the login page and in Activate an Account; it shows the typed
  password so staff can check it before saving (eye with a slash while shown).
  Hidden by default; it does not submit or close the dialog.
- **Commit:** _(not yet pushed)_

## 2026-11-04

### 14. User Management — staff no longer see Reset Password on staff and Super Admin rows
- **Screen:** User Management → Actions (staff with the reset permission).
- **Before:** Reset Password appeared on every row, including Super Admins.
- **Now:** for staff it appears only on student and teacher rows. The Super Admin
  still sees it on every row.
- **Commit:** _(not yet pushed)_

### 15. Activate an Account — new refusal for earlier staff and Super Admin accounts
- **Screen:** School Records → Activate an Account (staff).
- **Before:** staff could activate a waiting staff or Super Admin account.
- **Now:** Look up shows "ID number … has an earlier staff or Super Admin account,
  so only a Super Admin can activate it." The Super Admin sees the normal steps.
- **Commit:** _(not yet pushed)_

### 16. A deactivated user is signed out on their next action
- **Screen:** any signed-in screen (the deactivated user).
- **Before:** they could keep using the system for up to 7 days, although the
  Deactivate dialog said they would be signed out.
- **Now:** their next request sends them to the login page, as the dialog says.
  The login page does not yet explain why.
- **Commit:** _(not yet pushed)_

### 17. Activate an Account — "same person or different person"
- **Screen:** School Records → Activate an Account (staff and Super Admin).
- **Before:** an earlier account's old name was never shown or compared.
- **Now:** when the earlier account's name differs from the list, a warning shows
  the old name and two choices: **Same person, the name was corrected** (keeps
  bookmarks and history) or **Different person** (new account; the old one stays
  closed). Continue stays off until one is chosen. The role shown updates with
  the choice. The earlier-account note appears only when the names match.
- **Commit:** _(not yet pushed)_

### 18. Activate an Account — one more refusal
- **Screen:** School Records → Activate an Account (staff).
- **Before:** staff could turn on a waiting account that staff had deactivated.
- **Now:** Look up says only a Super Admin can activate it.
- **Commit:** _(not yet pushed)_

### 19. School Records — import preview shows name and role changes, true totals
- **Screen:** School Records → Import a new list (Super Admin).
- **Before:** no word about changed names or roles; counts stopped at 50.
- **Now:** new sections list accounts with a **different name** (old → new, must
  be activated again) and accounts changing **between student and teacher**. If
  your own name differs, a note says it will update and you stay signed in. Every
  count is the true total, with "Showing the first 50 of N" under long lists. The
  confirmation and the result message mention all of this. The "not on the list"
  warning uses the true total too.
- **Commit:** _(not yet pushed)_

### 20. My Profile — name is read-only
- **Screen:** My Profile → Personal Info (everyone).
- **Before:** Full Name could be edited and was required.
- **Now:** it is greyed out, with "From the school's list. If it is wrong, please
  visit the library." Email can still be changed.
- **Commit:** _(not yet pushed)_

### 21. User Management — tabs and tiles cover every account
- **Screen:** User Management (staff and Super Admin).
- **Before:** tabs only filtered the 15 accounts on the current page, so they
  could look empty, and the tiles said "(page)" or "(shown)".
- **Now:** tabs search every account, with correct paging. The tiles read **Total
  Users, Active, Deactivated, Staff & Admins** and are true totals of activated
  accounts, whatever filter is on.
- **Commit:** _(not yet pushed)_

### 22. Activate an Account — "Different person" asks for confirmation
- **Screen:** School Records → Activate an Account (staff and Super Admin).
- **Before:** Continue went straight on to email and password.
- **Now:** a dialog, "Start a new account for [name]?", says the old account is
  closed for good and its bookmarks and history stay with it. **Yes, different
  person** goes on; **Cancel** returns to the same step with nothing changed.
  "Same person" has no extra question.
- **Commit:** _(not yet pushed)_

### 23. Login — Remember me removed
- **Screen:** Login (everyone).
- **Before:** a Remember me checkbox that did nothing.
- **Now:** no checkbox.
- **Commit:** _(not yet pushed)_

### 24. Login — says when you were signed out
- **Screen:** Login (anyone whose session ended).
- **Before:** they landed on the login page with no explanation.
- **Now:** a warning says "You have been signed out. Please sign in again." It
  shows once and goes away on reload. Trying to sign in then gives the specific
  reason, such as a deactivated account.
- **Commit:** _(not yet pushed)_

### 25. User Management — Reset Password and Change Role dialogs
- **Screen:** User Management → Actions (staff and Super Admin; Change Role is
  Super Admin only).
- **Before:** Cancel on Reset Password kept the typed password for the next
  account's dialog; Change Role said everyone signs up themselves.
- **Now:** Cancel clears the boxes. Change Role says everyone is activated at the
  library from the school's list.
- **Commit:** _(not yet pushed)_

### 26. Admin Dashboard and Reports — "Activated Accounts"
- **Screen:** Admin Dashboard, Reports (staff and Super Admin).
- **Before:** "Registered Users" counted every account, including those waiting
  to be activated.
- **Now:** "Activated Accounts" counts only accounts in use. The Users figure on
  the public landing page and the report PDF's Total Users use the same count.
- **Commit:** _(not yet pushed)_

## 2026-11-04 (continued)

### 27. School Records — Download full list
- **Screen:** School Records (Super Admin).
- **Before:** only the first 50 names of each section could be seen, and nothing
  said that skipped rows stop at 200.
- **Now:** the preview has **one** button above Import this list, "Download full
  lists (3 files)" (the number depends on the file), which saves a separate CSV for
  each section that applies: switched off, different name, role change, switched
  back on, skipped rows. With one section it reads "Download full list". The "not on
  the list" warning under Current list has its own button. Skipped rows also say
  "Showing the first 200 of N" when there are more. Each section had its own button
  at first; the user asked for one.
- **Commit:** _(not yet pushed)_

### 28. School Records — long lists are folded
- **Screen:** School Records, import preview and Current list (Super Admin).
- **Before:** up to 50 names per section, and up to 200 skipped rows, were always
  on screen, so Import this list could be a long way down.
- **Now:** a section with more than 10 names shows its count and a **Show the first
  50** link (**Show all N** when there are 50 or fewer), which changes to **Hide the
  names**. Sections with 10 or fewer still show the names straight away.
- **Commit:** _(not yet pushed)_

### 29. School Records — hide the "not on the list" warning
- **Screen:** School Records → Current list (Super Admin).
- **Before:** the warning stayed for as long as any account was not on the list.
- **Now:** a **Hide** link shrinks it to one line, such as "5 accounts are not on the
  list · Show". It stays hidden in that browser, and only for the current list: the
  next import shows the warning again. Accounts are not affected.
- **Commit:** _(not yet pushed)_

## 2026-11-05

### 30. School Records — Download CSV template
- **Screen:** School Records → Import a new list (Super Admin).
- **Before:** the columns were only described in the page's text.
- **Now:** a **Download CSV template** button saves `school-list-template.csv`, with
  just the required columns, to send to the school's IT.
- **Commit:** _(not yet pushed)_
