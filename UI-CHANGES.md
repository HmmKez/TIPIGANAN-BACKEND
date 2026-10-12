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
