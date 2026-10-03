<?php
/**
 * On-platform event registration (RSVP).
 *
 * Adds gates_event_registrations — one row per person who registers for a
 * site event. Until now "RSVP" was only an external link (gates_site_events
 * .rsvp_url) with no on-platform capture. Cross-DB via the schema builder;
 * idempotent.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();

if (!$schema->hasTable('gates_event_registrations')) {
    $schema->create('gates_event_registrations', function ($t) {
        $t->increments('id');
        $t->unsignedInteger('event_id');
        $t->string('name', 160);
        $t->string('email', 190);
        $t->string('phone', 40)->nullable();
        $t->string('ip_hash', 64)->nullable();
        $t->dateTime('created_at')->nullable();
        $t->unique(['event_id', 'email'], 'uq_evreg_event_email');
        $t->index('event_id', 'idx_evreg_event');
    });
    echo "  + gates_event_registrations created\n";
} else {
    echo "  = gates_event_registrations already present\n";
}

echo "event registrations OK\n";
