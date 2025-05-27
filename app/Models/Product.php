<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'onedrive_id',
        'name',
        'short_name',
        'slug',
        'thumb_image',
        'banner_image',
        'download_file',
        'vendor_id',
        'category_id',
        'sub_category_id',
        'child_category_id',
        'brand_id',
        'qty',
        'short_description',
        'long_description',
        'video_link',
        'sku',
        'seo_title',
        'seo_description',
        'price',
        'offer_price',
        'offer_start_date',
        'offer_end_date',
        'tax_id',
        'is_cash_delivery',
        'is_return',
        'return_policy_id',
        'tags',
        'is_warranty',
        'show_homepage',
        'is_undefine',
        'is_featured',
        'serial',
        'is_wholesale',
        'new_product',
        'is_top',
        'is_best',
        'is_flash_deal',
        'flash_deal_date',
        'buyone_getone',
        'status',
        'last_synced_at',
        'is_specification',
    ];


    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
