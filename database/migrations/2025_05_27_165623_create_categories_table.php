<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->string('icon', 255);
            $table->integer('status')->default(0);
            $table->double('price')->default(0);
            $table->string('thumb_image', 255)->nullable();
            $table->integer('is_featured')->default(0);
            $table->integer('is_top')->default(0);
            $table->integer('is_popular')->default(0);
            $table->integer('is_trending')->default(0);
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
