<?php

namespace AfricaGates\Models;

use Illuminate\Database\Eloquent\Model;

class HomeFeature extends Model
{
    protected $table = 'gates_home_features';

    protected $fillable = [
        'type',
        'title',
        'body',
        'image_path',
        'tag',
        'link_url',
        'link_text',
        'author_name',
        'author_role',
        'issuer_name',
        'issuer_initial',
        'issuer_color',
        'issuer_type',
        'quote',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function getActiveByType(string $type)
    {
        return static::where('type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();
    }
}
