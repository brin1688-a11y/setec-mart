<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'position'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A pasted URL is used as-is; an uploaded file is resolved on the
     * "public" disk. Same rule the single-image column used.
     */
    public function url(): string
    {
        if (str_starts_with($this->path, 'http://') || str_starts_with($this->path, 'https://')) {
            return $this->path;
        }

        return Storage::url($this->path);
    }

    public function isUploaded(): bool
    {
        return ! str_starts_with($this->path, 'http://') && ! str_starts_with($this->path, 'https://');
    }
}
