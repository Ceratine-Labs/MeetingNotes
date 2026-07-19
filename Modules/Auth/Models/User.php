<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Core\Traits\HasUuid;

class User extends Authenticatable
{
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
