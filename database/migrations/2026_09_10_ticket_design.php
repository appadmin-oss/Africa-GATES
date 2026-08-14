<?php
/**
 * A ticket that looks like a ticket, and that an organiser can put their own face on.
 *
 * ── WHY THE TICKET NEEDED ITS OWN COLUMNS ────────────────────────────────────
 *
 * The ticket page rendered the code, the name, the tier and the price on a plain white card.
 * Everything on it was correct and none of it was recognisable: an attendee holding a screen
 * up at a door could not tell at a glance that they were holding a ticket rather than a
 * receipt, and there was nothing on it that said WHOSE event it was except a line of text.
 *
 * `gates_site_events.cover_image` has existed all along and the ticket never looked at it.
 * That is the same shape of gap this codebase keeps finding — a column nothing reads — and
 * fixing it is most of the visual difference on its own.
 *
 * ── WHY IT IS PER EVENT AND NOT A SITE SETTING ───────────────────────────────
 *
 * This platform runs an awards ceremony, a summit and a training day in the same year, and
 * they do not share a look. A single site-wide ticket theme would mean every event after the
 * first is wearing somebody else's clothes. So the appearance lives on the event row, beside
 * the other things the organiser of that event decides.
 *
 * ── AND WHY EVERY DEFAULT IS NULL ────────────────────────────────────────────
 *
 * NULL here means "unconfigured", and unconfigured must render EXACTLY the ticket that
 * shipped before this migration. An organiser who never opens the new panel must not
 * discover that their tickets changed colour. That is why there is no `DEFAULT '#10292c'`
 * anywhere below: a stored default is indistinguishable from a choice, so the first time
 * somebody wanted to change the platform's own house colour, every event that had never
 * been touched would be pinned to the old one.
 *
 *   ticket_accent     a hex colour. VALIDATED IN PHP BEFORE IT IS WRITTEN and again before
 *                     it reaches a style attribute — see EventTicketDesign::colour(). A
 *                     colour that goes straight from a form into `style="…"` is a CSS
 *                     injection point, and `expression(...)`/`url(...)` payloads are the
 *                     reason this is not just a VARCHAR the template trusts.
 *   ticket_theme      'light' | 'dark' | NULL. Not a free string in the template.
 *   ticket_image      an override for the ticket only. An organiser's cover art is often a
 *                     wide hero that crops badly into a ticket header, and forcing them to
 *                     choose between a good event page and a good ticket is a false choice.
 *   ticket_note       one short line on the stub: "Doors 5pm · Black tie". Printed, so it is
 *                     the thing somebody re-reads on the day.
 *   ticket_rows       which optional detail rows show, as a comma list. An organiser running
 *                     a free community day does not want a "Paid ₦0" row, and one running a
 *                     gala dinner very much wants the table number.
 *   ticket_show_qr    on unless explicitly off. There is one real reason to turn it off — a
 *                     door that checks names against a printed list — and for everyone else
 *                     hiding the QR makes the queue slower, so it is opt-out and not opt-in.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_site_events')) {
    echo "  ! gates_site_events missing — nothing to do\n";
    echo "ticket design OK\n";
    return;
}

foreach ([
    'ticket_accent'  => $sqlite ? 'TEXT' : 'VARCHAR(7) NULL',
    'ticket_theme'   => $sqlite ? 'TEXT' : 'VARCHAR(10) NULL',
    'ticket_image'   => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
    'ticket_note'    => $sqlite ? 'TEXT' : 'VARCHAR(160) NULL',
    'ticket_rows'    => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
    // Nullable on purpose, tri-state: NULL = never chosen (show it), 1 = chosen yes,
    // 0 = chosen no. A NOT NULL DEFAULT 1 would lose the distinction, and this codebase
    // has been bitten before by flattening "untracked" into a value.
    'ticket_show_qr' => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_site_events', $col)) {
        DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_site_events.{$col} added\n";
    } else {
        echo "  = gates_site_events.{$col} already present\n";
    }
}

// ── The seat/table label an organiser writes on a single registration ────────
//
// A gala with numbered tables needs somewhere to put "Table 12", and that is per attendee,
// not per event. It is a free-text label rather than an integer because organisers use
// "Table 12", "Row C Seat 7" and "Balcony left" interchangeably and a schema that insists
// on a number gets "12" typed into a phone column instead.
foreach ([
    'seat_label' => $sqlite ? 'TEXT' : 'VARCHAR(60) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_event_registrations', $col)) {
        DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_event_registrations.{$col} added\n";
    } else {
        echo "  = gates_event_registrations.{$col} already present\n";
    }
}

echo "ticket design OK\n";
