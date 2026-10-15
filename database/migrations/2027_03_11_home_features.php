<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return new class {
    public function up(): void
    {
        if (!Capsule::schema()->hasTable('gates_home_features')) {
            Capsule::schema()->create('gates_home_features', function ($table) {
                $table->bigIncrements('id');
                $table->string('type', 50)->default('hero_slide'); // hero_slide, voice, topic, quick_start
                $table->string('title', 255);
                $table->text('body')->nullable();
                $table->string('image_path', 400)->nullable();
                $table->string('tag', 100)->nullable();
                $table->string('link_url', 400)->nullable();
                $table->string('link_text', 100)->nullable();
                $table->string('author_name', 150)->nullable();
                $table->string('author_role', 150)->nullable();
                $table->string('issuer_name', 150)->nullable();
                $table->string('issuer_initial', 10)->nullable();
                $table->string('issuer_color', 30)->nullable();
                $table->string('issuer_type', 50)->nullable();
                $table->text('quote')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['type', 'is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('gates_home_features');
    }
};
