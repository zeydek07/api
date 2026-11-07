<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Mümessil" rolü "Saha Personeli" rolüne katılır: hastaneye ürün bırakma, geri alma,
 * ameliyat kullanımı, iade ve sayım aynı kişide. Mümessil kullanıcıları ve yetkileri taşınır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rep = DB::table('roles')->where('slug', 'mumessil')->first();
        if (! $rep) {
            return;
        }

        $field = DB::table('roles')->where('slug', 'personel')->first();
        if (! $field) {
            DB::table('roles')->where('id', $rep->id)->update(['slug' => 'personel', 'name' => 'Saha Personeli']);

            return;
        }

        DB::transaction(function () use ($rep, $field) {
            $have = DB::table('permission_role')->where('role_id', $field->id)->pluck('permission_id');
            $add = DB::table('permission_role')->where('role_id', $rep->id)->whereNotIn('permission_id', $have)->pluck('permission_id');
            DB::table('permission_role')->insert($add->map(fn ($id) => ['role_id' => $field->id, 'permission_id' => $id])->all());

            DB::table('users')->where('role_id', $rep->id)->update(['role_id' => $field->id]);
            DB::table('permission_role')->where('role_id', $rep->id)->delete();
            DB::table('roles')->where('id', $rep->id)->delete();
            DB::table('roles')->where('id', $field->id)->update(['name' => 'Saha Personeli']);
        });
    }

    public function down(): void
    {
        // Geri alınamaz: kullanıcıların hangisinin mümessil olduğu bilgisi birleştirmede kaybolur.
    }
};
