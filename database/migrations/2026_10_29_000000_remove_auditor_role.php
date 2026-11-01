<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (Role::where('name', 'auditor')->get() as $role) {
                foreach (User::withTrashed()->role($role)->get() as $user) {
                    $user->removeRole($role);

                    if (! $user->hasAnyRole(['super-admin', 'member'])) {
                        $user->syncPermissions([]);
                        $user->forceFill(['active' => false, 'remember_token' => null])->save();
                        if (! $user->trashed()) {
                            $user->delete();
                        }
                    }

                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                $role->delete();
            }
        });

        $disk = Storage::disk('local');
        if ($disk->exists('demo-accounts.txt')) {
            $accounts = preg_replace('/^Auditor:.*\R?/m', '', $disk->get('demo-accounts.txt'));
            $disk->put('demo-accounts.txt', str_replace(['Super Admin:', 'ketiga akun'], ['Admin:', 'kedua akun'], $accounts));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retired accounts and their access are not restored on rollback.
    }
};
