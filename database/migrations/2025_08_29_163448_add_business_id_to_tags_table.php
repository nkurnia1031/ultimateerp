<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom business_id
        Schema::table('tags', function (Blueprint $table) {
            $table->foreign('business_id')
                ->references('id')
                ->on('business')
                ->onDelete('cascade');
        });

        // Tambah permissions untuk route tags (resource)
        $permissions = [
            'tags.index',
            'tags.create',
            'tags.store',
            'tags.show',
            'tags.edit',
            'tags.update',
            'tags.destroy',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }
    }

    public function down(): void
    {
        // Hapus kolom business_id
        Schema::table('tags', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropIndex(['business_id']);
            $table->dropColumn('business_id');
        });

        // Hapus permissions
        $permissions = [
            'tags.index',
            'tags.create',
            'tags.store',
            'tags.show',
            'tags.edit',
            'tags.update',
            'tags.destroy',
        ];

        foreach ($permissions as $perm) {
            Permission::where('name', $perm)->delete();
        }
    }
};
