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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('onedrive_id', 255)->nullable();
            $table->string('name', 255);
            $table->string('short_name', 255);
            $table->string('slug', 255);
            $table->string('thumb_image', 255);
            $table->string('banner_image', 191)->nullable();
            $table->string('download_file', 255)->nullable();
            $table->integer('vendor_id')->default(0);
            $table->integer('category_id');
            $table->integer('sub_category_id')->default(0);
            $table->integer('child_category_id')->default(0);
            $table->integer('brand_id');
            $table->integer('qty');
            $table->text('short_description');
            $table->longText('long_description');
            $table->string('video_link', 255)->nullable();

            // New fields from the second image
            $table->string('sku', 255)->nullable();
            $table->text('seo_title');
            $table->text('seo_description');
            $table->double('price');
            $table->double('offer_price')->nullable();
            $table->date('offer_start_date')->nullable();
            $table->date('offer_end_date')->nullable();
            $table->integer('tax_id');
            $table->tinyInteger('is_cash_delivery')->default(0);
            $table->tinyInteger('is_return')->default(0);
            $table->integer('return_policy_id')->nullable();
            $table->text('tags')->nullable();
            $table->tinyInteger('is_warranty')->default(0);
            $table->tinyInteger('show_homepage')->default(0);

            // Boolean flags with default 0
            $table->tinyInteger('is_undefine')->default(0);
            $table->tinyInteger('is_featured')->default(0);
            $table->integer('serial')->nullable();
            $table->integer('is_wholesale')->default(0);
            $table->tinyInteger('new_product')->default(0);
            $table->tinyInteger('is_top')->default(0);
            $table->tinyInteger('is_best')->default(0);
            $table->tinyInteger('is_flash_deal')->default(0);
            $table->date('flash_deal_date')->nullable();
            $table->tinyInteger('buyone_getone')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('is_specification')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
