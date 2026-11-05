<?php
declare(strict_types=1);
namespace Tests\Support;

/** Source-contract tests follow the editor's modular templates and script. */
final class EventEditorSource
{
    public static function read(): string
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/templates/admin/events/form.twig');
        foreach (glob($root . '/templates/admin/events/editor/*.twig') as $file) $source .= "\n" . file_get_contents($file);
        $source .= "\n" . file_get_contents($root . '/templates/admin/events/navigation.twig');
        return $source . "\n" . file_get_contents($root . '/public/assets/js/admin-event-editor.js');
    }
}
