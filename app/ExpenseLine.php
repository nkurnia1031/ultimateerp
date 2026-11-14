<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseLine extends Model
{

    protected $table = 'expense_lines';

    protected $fillable = [
        'transaction_id',
        'category_id',
        'sub_category_id',
        'description',
        'note',
        'amount',
        'document',
    ];

    /**
     * Relasi ke Transaction
     * Banyak expense_lines dimiliki oleh 1 transaksi
     */
    public function transaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'transaction_id');
    }

    /**
     * Relasi ke Category
     */
    public function category()
    {
        return $this->belongsTo(\App\ExpenseCategory::class, 'category_id');
    }

    /**
     * Relasi ke Sub Category
     */
    public function subCategory()
    {
        return $this->belongsTo(\App\ExpenseCategory::class, 'sub_category_id');
    }
    /**
     * Retrieves documents path if exists
     */
    public function getDocumentPathAttribute()
    {
        $path = ! empty($this->document) ? asset('/uploads/documents/' . $this->document) : null;

        return $path;
    }

    /**
     * Removes timestamp from document name
     */
    public function getDocumentNameAttribute()
    {
        $document_name = ! empty(explode('_', $this->document, 2)[1]) ? explode('_', $this->document, 2)[1] : $this->document;

        return $document_name;
    }
}
