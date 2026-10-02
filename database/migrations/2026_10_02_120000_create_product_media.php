<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product photos & videos (docs/woocommerce-bridge-plan.md §0). A row is an
 * uploaded image (+ generated thumbnail), an uploaded video, or a video LINK
 * (YouTube/Vimeo/Instagram — `url`, no file). Attached to any product; on a
 * variable parent it may be tied to ONE colour value, so every size of «Μαύρο»
 * shares the black photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Colour (or any axis value) the media belongs to; the value going away
            // just makes it general to the product.
            $table->foreignId('product_attribute_value_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);                      // image | video | video_link
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('url', 500)->nullable();          // video_link only
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt', 255)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
